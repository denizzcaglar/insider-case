<?php

declare(strict_types=1);

use App\Http\Controllers\Api\FixturesController;
use App\Http\Controllers\Api\LeagueController;
use App\Http\Controllers\Api\MatchCommentaryController;
use App\Http\Controllers\Api\PredictionController;
use App\Http\Controllers\Api\PredictionSnapshotController;
use App\Http\Controllers\Api\SeasonsController;
use App\Http\Controllers\Api\StandingsController;
use App\Http\Controllers\Api\WeekController;
use App\Http\Middleware\RejectHistoricalSeasonMutation;
use Illuminate\Support\Facades\Route;

Route::get('/seasons', [SeasonsController::class, 'index']);

Route::get('/standings', [StandingsController::class, 'index']);

Route::get('/fixtures', [FixturesController::class, 'index']);
Route::get('/fixtures/{fixture}/commentary', [MatchCommentaryController::class, 'show']);

Route::get('/predictions', [PredictionController::class, 'index']);
Route::get('/predictions/snapshots', [PredictionSnapshotController::class, 'index']);

Route::middleware(RejectHistoricalSeasonMutation::class)->group(function () {
    Route::patch('/fixtures/{fixture}', [FixturesController::class, 'update']);
    Route::post('/weeks/next', [WeekController::class, 'next']);
    Route::post('/weeks/play-all', [WeekController::class, 'playAll']);
    Route::post('/league/reset', [LeagueController::class, 'reset']);
});
