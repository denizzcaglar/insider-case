<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    protected $fillable = [
        'name',
        'short_name',
        'attack',
        'defense',
        'overall',
        'home_advantage',
        'external_ref',
    ];

    protected $casts = [
        'attack' => 'integer',
        'defense' => 'integer',
        'overall' => 'integer',
        'home_advantage' => 'decimal:3',
    ];

    public function homeFixtures(): HasMany
    {
        return $this->hasMany(Fixture::class, 'home_team_id');
    }

    public function awayFixtures(): HasMany
    {
        return $this->hasMany(Fixture::class, 'away_team_id');
    }

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }
}
