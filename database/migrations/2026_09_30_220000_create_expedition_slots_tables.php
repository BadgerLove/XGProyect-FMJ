<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expedition slots (Dale, 30 Sep 2026): every system gets a hidden, random number of "good" expeditions
 * per 6-hour window (1-15, mostly 1-6). A fleet that ARRIVES while the system is under 100 % claims a
 * slot and draws its normal card; one that arrives when it is full comes back empty ("picked clean").
 * Replaces the old wear (expedition_activity: 20-count threshold, 2/hour recovery, ~9 % effect).
 * See App\Services\Game\Formulas\ExpeditionSlotService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expedition_slots', function (Blueprint $table) {
            $table->unsignedSmallInteger('galaxy');
            $table->unsignedSmallInteger('system');
            $table->unsignedInteger('window_start');   // unix: start of the 6-hour window (UK time)
            $table->unsignedTinyInteger('capacity');   // hidden: good expeditions this window (1-15)
            $table->unsignedTinyInteger('used')->default(0);
            $table->primary(['galaxy', 'system', 'window_start']);
            $table->index('window_start');
        });

        Schema::create('expedition_claims', function (Blueprint $table) {
            $table->unsignedBigInteger('fleet_id')->primary();
            $table->unsignedSmallInteger('galaxy');
            $table->unsignedSmallInteger('system');
            $table->unsignedInteger('window_start');
            $table->boolean('granted')->default(false);
            $table->unsignedInteger('claimed_at');
            $table->index('claimed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expedition_claims');
        Schema::dropIfExists('expedition_slots');
    }
};
