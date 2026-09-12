<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read model (CQRS query side): one row per earning line with its current numbers, kept up
 * to date by the projector. It can be dropped and rebuilt from earning_line_events at any time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('earning_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('currency', 3);
            $table->bigInteger('system_value_minor');
            $table->bigInteger('current_value_minor');
            $table->unsignedInteger('adjustment_count');
            $table->unsignedInteger('frozen_at_version')->nullable();
            $table->unsignedInteger('version');
            $table->dateTime('calculated_at', 6);
            $table->dateTime('last_event_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('earning_lines');
    }
};
