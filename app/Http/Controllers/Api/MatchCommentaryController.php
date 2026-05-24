<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Fixture;
use App\Services\Commentary\Exceptions\CommentaryGenerationException;
use App\Services\Commentary\MatchCommentaryService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * Returns a short LLM-generated narrative for a single played fixture.
 *
 * The first call to this endpoint for a given fixture triggers a Gemini
 * round-trip (~1-2s). Subsequent calls return the cached row instantly.
 * Editing the fixture invalidates the cache; resetting the league cascades
 * the deletion via the foreign key.
 */
final class MatchCommentaryController extends Controller
{
    public function __construct(private readonly MatchCommentaryService $service)
    {
    }

    public function show(Fixture $fixture): JsonResponse
    {
        try {
            $content = $this->service->for($fixture);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        } catch (CommentaryGenerationException $e) {
            return new JsonResponse([
                'message' => 'Commentary service unavailable; please try again.',
            ], 503);
        }

        return new JsonResponse([
            'fixture_id' => (int) $fixture->id,
            'home_goals' => (int) $fixture->home_goals,
            'away_goals' => (int) $fixture->away_goals,
            'commentary' => $content,
        ]);
    }
}
