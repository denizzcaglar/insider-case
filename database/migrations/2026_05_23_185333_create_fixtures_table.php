<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fixtures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('week');
            $table->foreignId('home_team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('away_team_id')->constrained('teams')->cascadeOnDelete();
            $table->boolean('played')->default(false);
            $table->unsignedSmallInteger('home_goals')->nullable();
            $table->unsignedSmallInteger('away_goals')->nullable();
            $table->timestamp('simulated_at')->nullable();
            $table->timestamps();

            $table->unique(['season_id', 'home_team_id', 'away_team_id']);
            $table->index(['season_id', 'week']);
            $table->index(['season_id', 'played']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fixtures');
    }
};
