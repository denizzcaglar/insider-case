<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediction_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained('seasons')->cascadeOnDelete();
            $table->unsignedTinyInteger('week_number');
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->decimal('probability', 5, 2);
            $table->timestamps();

            $table->unique(['season_id', 'week_number', 'team_id'], 'prediction_snapshots_unique');
            $table->index(['season_id', 'week_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_snapshots');
    }
};
