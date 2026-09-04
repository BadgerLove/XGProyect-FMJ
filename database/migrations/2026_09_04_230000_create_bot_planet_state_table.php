<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bot-only per-planet state for the idle-escalation ladder (see ogame-vault/bot-ai/Bot Self-Healing Plan.md).
 * Deliberately NOT a column on `planets` — that table belongs to the game.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_planet_state', function (Blueprint $table) {
            $table->unsignedInteger('planet_id')->primary();
            $table->unsignedInteger('idle_ticks')->default(0);     // consecutive active ticks with nothing to build/research
            $table->unsignedTinyInteger('rung')->default(0);        // highest rung applied in the current idle streak
            $table->unsignedInteger('last_action_at')->default(0);  // unix: last tick that queued something here
            $table->unsignedInteger('last_rung_at')->default(0);    // unix: last tick a rung fired
            $table->string('note', 120)->nullable();                // what the ladder last did
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_planet_state');
    }
};
