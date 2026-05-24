<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cached LLM-generated commentary for a single played fixture.
 *
 * One row per fixture (enforced by a UNIQUE constraint on fixture_id).
 * Score columns are stored so staleness is observable at the DB level;
 * editing the fixture deletes the row, and resetting the league cascades
 * via the foreign key.
 */
final class MatchCommentary extends Model
{
    protected $fillable = [
        'fixture_id',
        'home_goals',
        'away_goals',
        'content',
    ];

    protected $casts = [
        'home_goals' => 'integer',
        'away_goals' => 'integer',
    ];

    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }
}
