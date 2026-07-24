<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_grudges', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('defender_id')->index();  // The bot that got attacked
            $table->unsignedInteger('attacker_id')->index();  // The player/bot that attacked
            $table->unsignedInteger('attack_count')->default(1);
            $table->string('severity', 20)->default('mild');  // mild, annoyed, vendetta
            $table->timestamp('last_attack')->index();
            $table->timestamps();

            // Composite index for fast lookups
            $table->index(['defender_id', 'attacker_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_grudges');
    }
};
