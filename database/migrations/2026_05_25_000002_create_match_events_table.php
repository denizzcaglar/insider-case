<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixture_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('second');
            $table->string('type', 32);
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->json('detail')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['fixture_id', 'second']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_events');
    }
};
