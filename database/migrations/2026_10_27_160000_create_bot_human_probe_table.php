<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Last bot probe at each human-owned planet. BotTick lets no bot probe a human planet again until
 * HUMAN_PROBE_COOLDOWN_SECONDS have passed since ANY bot did - every probe is an "Espionage action"
 * message in the human's inbox (Oojerfink: 28 on 27 Oct from 33 bots in range). See BotTick Phase 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_human_probe', function (Blueprint $table) {
            $table->integer('galaxy');
            $table->integer('system');
            $table->integer('planet');
            $table->unsignedInteger('probed_at');        // unix: when the last bot spy fleet left for it
            $table->unsignedInteger('bot_user_id');      // the bot that sent it
            $table->primary(['galaxy', 'system', 'planet']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_human_probe');
    }
};
