<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expedition_deck', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->unique();
            $table->json('deck');       // shuffled array of outcome strings
            $table->integer('pointer'); // current position in deck (0-based)
            $table->json('weights')->nullable(); // snapshot of admin weights when deck was built
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expedition_deck');
    }
};
