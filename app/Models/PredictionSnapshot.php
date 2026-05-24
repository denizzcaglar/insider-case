<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (season, week, team) tuple. Records each team's championship-win
 * probability as of the end of a given week — the data feed for the line chart.
 */
final class PredictionSnapshot extends Model
{
    protected $fillable = [
        'season_id',
        'week_number',
        'team_id',
        'probability',
    ];

    protected $casts = [
        'week_number' => 'integer',
        'probability' => 'float',
    ];

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
