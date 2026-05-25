<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Fixture;
use App\Services\Commentary\Exceptions\CommentaryGenerationException;
use App\Services\Commentary\MatchCommentaryService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

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
            $message = str_contains($e->getMessage(), 'API key')
                ? 'API key not configured. Please enter your Gemini API key to enable match commentary.'
                : 'Commentary service unavailable; please try again.';

            return new JsonResponse(['message' => $message], 503);
        }

        return new JsonResponse([
            'fixture_id' => (int) $fixture->id,
            'home_goals' => (int) $fixture->home_goals,
            'away_goals' => (int) $fixture->away_goals,
            'commentary' => $content,
        ]);
    }
}
