<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table): void {
            $table->string('tenant_id', 64)->nullable()->after('rng_seed');
            $table->timestamp('last_seen_at')->nullable()->after('is_historical');
            $table->index(['tenant_id', 'is_historical']);
        });
    }

    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'is_historical']);
            $table->dropColumn(['tenant_id', 'last_seen_at']);
        });
    }
};
