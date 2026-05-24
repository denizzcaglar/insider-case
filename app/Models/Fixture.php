<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fixture extends Model
{
    protected $fillable = [
        'season_id',
        'week',
        'home_team_id',
        'away_team_id',
        'played',
        'home_goals',
        'away_goals',
        'simulated_at',
    ];

    protected $casts = [
        'week' => 'integer',
        'played' => 'boolean',
        'home_goals' => 'integer',
        'away_goals' => 'integer',
        'simulated_at' => 'datetime',
    ];

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    public function scopePlayed(Builder $query): Builder
    {
        return $query->where('played', true);
    }

    public function scopeUnplayed(Builder $query): Builder
    {
        return $query->where('played', false);
    }
}
