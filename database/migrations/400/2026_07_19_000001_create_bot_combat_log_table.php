<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_combat_log', function (Blueprint $table) {
            $table->id();
            $table->integer('attacker_id')->index();       // user ID of attacker
            $table->integer('defender_id')->index();       // user ID of defender
            $table->integer('fleet_id')->nullable();       // fleet ID
            $table->string('target_coords', 20);           // "g:s:p"
            $table->string('result', 10)->index();         // win, loss, draw
            $table->integer('attacker_ships_sent');        // total ships sent
            $table->integer('attacker_ships_lost');        // total ships lost
            $table->integer('defender_ships_sent')->default(0);
            $table->integer('defender_ships_lost')->default(0);
            $table->bigInteger('loot_metal')->default(0);
            $table->bigInteger('loot_crystal')->default(0);
            $table->bigInteger('loot_deuterium')->default(0);
            $table->bigInteger('debris_metal')->default(0);
            $table->bigInteger('debris_crystal')->default(0);
            $table->text('attacker_fleet')->nullable();    // JSON: {ship_id: count}
            $table->text('defender_fleet')->nullable();    // JSON: {ship_id: count}
            $table->float('loss_rate')->default(0);        // attacker loss rate 0-1
            $table->timestamp('created_at')->useCurrent();

            $table->index(['attacker_id', 'created_at']);
            $table->index(['defender_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_combat_log');
    }
};
