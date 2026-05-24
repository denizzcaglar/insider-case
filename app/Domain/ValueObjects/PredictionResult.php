<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

final readonly class PredictionResult
{
    /**
     * @param  array<int, float>  $titleProbabilities  Keyed by team_id, values in [0, 100]; sums to ~100.
     * @param  array<int, StrengthBreakdown>  $modelInputs  Keyed by team_id; the seed/prior/form/effective numbers that fed the predictor.
     */
    public function __construct(
        public array $titleProbabilities,
        public int $iterations,
        public ?string $seed,
        public array $modelInputs = [],
    ) {
    }
}
