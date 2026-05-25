<?php

declare(strict_types=1);

namespace App\Services\Commentary;

use App\Domain\ValueObjects\StandingsTable;
use App\Models\Fixture;
use App\Services\Commentary\Exceptions\CommentaryGenerationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

// Gemini-backed narrative commentary; swap providers by replacing this class.
final class CommentaryGenerator
{
    private const ENDPOINT_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';
    private const REQUEST_TIMEOUT_SECONDS = 10;

    public function generate(
        Fixture $fixture,
        StandingsTable $before,
        StandingsTable $after,
    ): string {
        $apiKey = (string) config('commentary.api_key');
        if ($apiKey === '') {
            throw new CommentaryGenerationException('Gemini API key is not configured.');
        }

        $model = (string) config('commentary.model', 'gemini-2.5-flash');
        $url = sprintf('%s/%s:generateContent?key=%s', self::ENDPOINT_BASE, $model, $apiKey);

        $prompt = $this->buildPrompt($fixture, $before, $after);

        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->acceptJson()
                ->post($url, [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => [
                        'temperature' => 0.85,
                        'maxOutputTokens' => 256,
                        // Disable thinking: it eats the maxOutputTokens budget silently.
                        'thinkingConfig' => ['thinkingBudget' => 0],
                    ],
                ]);
        } catch (Throwable $e) {
            Log::warning('Gemini commentary request threw an exception', ['error' => $e->getMessage()]);
            throw new CommentaryGenerationException('Failed to reach the commentary service.', previous: $e);
        }

        if (! $response->successful()) {
            Log::warning('Gemini commentary returned non-2xx', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new CommentaryGenerationException("Commentary service returned HTTP {$response->status()}.");
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (! is_string($text) || trim($text) === '') {
            Log::warning('Gemini commentary response missing expected text', ['body' => $response->body()]);
            throw new CommentaryGenerationException('Commentary service returned an unexpected response shape.');
        }

        return trim($text);
    }

    private function buildPrompt(Fixture $fixture, StandingsTable $before, StandingsTable $after): string
    {
        $home = $fixture->homeTeam;
        $away = $fixture->awayTeam;

        $beforeHome = $this->lineFor($before, (int) $home->id, $home->name);
        $beforeAway = $this->lineFor($before, (int) $away->id, $away->name);
        $afterHome = $this->lineFor($after, (int) $home->id, $home->name);
        $afterAway = $this->lineFor($after, (int) $away->id, $away->name);

        return <<<PROMPT
            You are a Premier League football commentator. Generate a 2-sentence summary
            of the following match. Be specific to the teams and the result; do not use
            filler phrases.

            Match: {$home->name} {$fixture->home_goals} - {$fixture->away_goals} {$away->name}
            Week: {$fixture->week} of 6 in the Insider League

            Before this match:
            - {$beforeHome}
            - {$beforeAway}

            After this match:
            - {$afterHome}
            - {$afterAway}

            Respond with exactly 2 sentences. No greetings, no signature, no markdown.
            PROMPT;
    }

    private function lineFor(StandingsTable $table, int $teamId, string $teamName): string
    {
        foreach ($table->rows() as $position => $row) {
            if ($row->teamId === $teamId) {
                return sprintf('%s: %d points (position %d)', $teamName, $row->points(), $position + 1);
            }
        }

        return sprintf('%s: 0 points (position unknown)', $teamName);
    }
}
