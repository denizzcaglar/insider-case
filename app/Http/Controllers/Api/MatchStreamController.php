<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Contracts\MatchSimulatorFactory;
use App\Domain\ValueObjects\TeamStrength;
use App\Http\Controllers\Controller;
use App\Http\Requests\WatchFixtureRequest;
use App\Models\Fixture;
use App\Models\MatchEvent;
use App\Services\League\LeagueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MatchStreamController extends Controller
{
    private const LOCK_TIMEOUT_SECONDS = 30;
    private const LOCK_BLOCK_SECONDS = 10;

    public function __construct(
        private readonly MatchSimulatorFactory $factory,
        private readonly LeagueService $league,
    ) {}

    public function stream(WatchFixtureRequest $request, Fixture $fixture): StreamedResponse|JsonResponse
    {
        $season = $fixture->season;
        if ($season === null) {
            return new JsonResponse(['message' => 'Fixture has no season.'], 404);
        }
        if ((bool) $season->is_historical) {
            return new JsonResponse(['message' => 'Historical seasons are read-only.'], 403);
        }

        $hasEvents = $fixture->events()->exists();
        $isPlayed = (bool) $fixture->played;

        if ($isPlayed && ! $hasEvents) {
            return new JsonResponse([
                'message' => 'This fixture was played via the fast engine; re-watching would rewrite the score. Reset the season to enable watching.',
            ], 409);
        }

        if (! $isPlayed) {
            $simulated = $this->simulateUnderLock($fixture);
            if ($simulated instanceof JsonResponse) {
                return $simulated;
            }
        }

        $events = $fixture->events()->with('player:id,name')->orderBy('second')->get();

        return $this->sseResponse(
            events: $events,
            score: ['home' => (int) $fixture->fresh()->home_goals, 'away' => (int) $fixture->fresh()->away_goals],
            speed: $request->speed(),
        );
    }

    private function simulateUnderLock(Fixture $fixture): ?JsonResponse
    {
        $lock = Cache::lock("watch:fixture:{$fixture->id}", self::LOCK_TIMEOUT_SECONDS);

        try {
            if (! $lock->block(self::LOCK_BLOCK_SECONDS)) {
                return new JsonResponse(['message' => 'Watch already in progress, try again.'], 503);
            }

            $fixture->refresh();
            if ((bool) $fixture->played && $fixture->events()->exists()) {
                return null;
            }
            if ((bool) $fixture->played) {
                return new JsonResponse([
                    'message' => 'Fixture played in another window without recordable events.',
                ], 409);
            }

            $fixture->loadMissing(['homeTeam', 'awayTeam', 'season']);
            $homeStrength = TeamStrength::fromTeam($fixture->homeTeam);
            $awayStrength = TeamStrength::fromTeam($fixture->awayTeam);

            $seed = 'watch:' . ($fixture->season->rng_seed ?? 'unseeded') . ':' . $fixture->id;
            $simulator = $this->factory->forWatching($seed);
            $result = $simulator->simulateWithEvents($homeStrength, $awayStrength);

            $this->league->recordWatchedFixture($fixture, $result);

            return null;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * @param  iterable<MatchEvent>  $events
     * @param  array{home:int, away:int}  $score
     */
    private function sseResponse(iterable $events, array $score, int $speed): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ];

        $isTesting = app()->runningUnitTests();

        return new StreamedResponse(function () use ($events, $score, $speed, $isTesting): void {
            // ob buffer drop skipped in tests (warning => failure).
            if (! $isTesting) {
                while (ob_get_level() > 0) {
                    @ob_end_clean();
                }
            }

            $previousSecond = 0;
            $totalEmitted = 0;

            foreach ($events as $event) {
                if (connection_aborted()) {
                    return;
                }

                $deltaSeconds = max(0, (int) $event->second - $previousSecond);
                $waitMicros = (int) ($deltaSeconds / max(1, $speed) * 1_000_000);
                if ($waitMicros > 0) {
                    usleep($waitMicros);
                }

                $payload = $this->payloadFor($event);
                echo "event: match-event\n";
                echo 'data: '.json_encode($payload)."\n\n";
                @ob_flush();
                @flush();

                $previousSecond = (int) $event->second;
                $totalEmitted++;
            }

            echo "event: complete\n";
            echo 'data: '.json_encode([
                'score_home' => $score['home'],
                'score_away' => $score['away'],
                'events_total' => $totalEmitted,
            ])."\n\n";
            @ob_flush();
            @flush();
        }, 200, $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(MatchEvent $event): array
    {
        return [
            'second' => (int) $event->second,
            'clock' => $event->clock(),
            'minute' => $event->minute,
            'type' => $event->type,
            'team_id' => $event->team_id !== null ? (int) $event->team_id : null,
            'player_id' => $event->player_id !== null ? (int) $event->player_id : null,
            'player_name' => $event->relationLoaded('player') && $event->player !== null ? $event->player->name : null,
            'detail' => $event->detail ?? [],
        ];
    }
}
