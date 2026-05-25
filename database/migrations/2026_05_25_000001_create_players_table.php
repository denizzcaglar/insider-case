<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('position', ['GK', 'DEF', 'MID', 'FWD']);
            $table->unsignedTinyInteger('pace');
            $table->unsignedTinyInteger('shooting');
            $table->unsignedTinyInteger('passing');
            $table->unsignedTinyInteger('dribbling');
            $table->unsignedTinyInteger('defending');
            $table->unsignedTinyInteger('physical');
            $table->unsignedTinyInteger('overall');
            $table->timestamps();
            $table->index(['team_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
