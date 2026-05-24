<?php

declare(strict_types=1);

namespace App\Services\Commentary\Exceptions;

use RuntimeException;

/**
 * Thrown when the LLM call fails: network error, non-2xx response, empty
 * candidates, or malformed JSON. The controller catches this and maps it
 * to a 503 response.
 */
final class CommentaryGenerationException extends RuntimeException
{
}
