<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

/**
 * Per-team breakdown of the inputs that fed the predictor for one prediction call.
 *
 * Surfaced in the GET /api/predictions response so reviewers can see exactly how
 * the seed strengths, the historical fit, and the current-season form combined
 * into the effective strengths that the Poisson simulator received.
 */
final readonly class StrengthBreakdown
{
    public function __construct(
        public int $teamId,
        public string $name,
        public string $shortName,
        public float $seedAttack,
        public float $seedDefense,
        public float $priorAttack,
        public float $priorDefense,
        public float $formAttack,
        public float $formDefense,
        public float $effectiveAttack,
        public float $effectiveDefense,
    ) {
    }
}
