<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

use App\Domain\ValueObjects\PredictionResult;
use App\Models\Season;

interface ChampionshipPredictor
{
    public function predict(Season $season, int $iterations, ?string $seed = null): PredictionResult;
}
