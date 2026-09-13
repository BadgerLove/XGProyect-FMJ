<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bot-only "leave this planet alone" list. When the battle engine rejects a human-owned target the bot
 * stops probing (and simulating) it for a while instead of re-probing every 30 minutes — each probe is
 * an "Espionage action" message in the human's inbox (53 in one day on 13 Sep). See BotTick Phase 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_target_skip', function (Blueprint $table) {
            $table->unsignedInteger('bot_user_id');
            $table->integer('galaxy');
            $table->integer('system');
            $table->integer('planet');
            $table->unsignedInteger('until');            // unix: no probes / sims at this planet before then
            $table->unsignedInteger('set_at');           // unix: when the rejection happened
            $table->string('reason', 32)->nullable();    // e.g. sim-rejected
            $table->primary(['bot_user_id', 'galaxy', 'system', 'planet']);
            $table->index('until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_target_skip');
    }
};
