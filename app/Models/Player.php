<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Player extends Model
{
    protected $fillable = [
        'team_id',
        'name',
        'position',
        'pace',
        'shooting',
        'passing',
        'dribbling',
        'defending',
        'physical',
        'overall',
    ];

    protected $casts = [
        'pace' => 'integer',
        'shooting' => 'integer',
        'passing' => 'integer',
        'dribbling' => 'integer',
        'defending' => 'integer',
        'physical' => 'integer',
        'overall' => 'integer',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
