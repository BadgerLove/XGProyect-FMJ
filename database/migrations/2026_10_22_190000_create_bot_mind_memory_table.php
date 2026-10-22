<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bot council notebook (Dale, 21-22 Oct 2026): one JSON row per bot that thinks with the council
 * (App\Services\Bot\Mind\Memory) - what its expeditions and recyclers brought home, its points over time
 * for the stuck-bot doctor, attacks it noticed, the doctor's prescriptions and a short decision diary.
 * Only the bot's own experience is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bot_mind_memory')) {
            return; // the simulator creates it on first use
        }
        Schema::create('bot_mind_memory', function (Blueprint $table) {
            $table->unsignedInteger('bot_id')->primary();
            $table->mediumText('data');
            $table->unsignedInteger('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_mind_memory');
    }
};
