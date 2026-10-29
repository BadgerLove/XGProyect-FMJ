<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Last bot attack sent at each human-owned planet. A bot spy report of a human planet scanned before that
 * moment is spent - one report, one attack - so the next attack needs a new probe (LunarWolf hit Oojerfink's
 * Cushion Alpha 8 times in 26 h on one 59.5K report, 29 Oct). See BotTick Phase 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_human_attack', function (Blueprint $table) {
            $table->integer('galaxy');
            $table->integer('system');
            $table->integer('planet');
            $table->unsignedInteger('attacked_at');      // unix: when the last bot attack/raid fleet left for it
            $table->unsignedInteger('bot_user_id');      // the bot that sent it
            $table->primary(['galaxy', 'system', 'planet']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_human_attack');
    }
};
