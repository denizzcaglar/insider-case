<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchEvent extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_KICKOFF = 'kickoff';
    public const TYPE_HALFTIME = 'halftime';
    public const TYPE_FULLTIME = 'fulltime';
    public const TYPE_SHOT = 'shot';
    public const TYPE_SAVE = 'save';
    public const TYPE_GOAL = 'goal';

    protected $fillable = [
        'fixture_id',
        'second',
        'type',
        'team_id',
        'player_id',
        'detail',
    ];

    protected $casts = [
        'second' => 'integer',
        'detail' => 'array',
    ];

    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function getMinuteAttribute(): int
    {
        return intdiv((int) $this->second, 60);
    }

    public function clock(): string
    {
        $s = (int) $this->second;

        return sprintf('%02d:%02d', intdiv($s, 60), $s % 60);
    }
}
