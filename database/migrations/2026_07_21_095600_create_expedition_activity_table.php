<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expedition_activity', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('galaxy');
            $table->unsignedSmallInteger('system');
            $table->unsignedInteger('expedition_count')->default(0);
            $table->timestamp('last_expedition')->nullable();
            $table->timestamps();

            // Unique index on galaxy:system
            $table->unique(['galaxy', 'system']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expedition_activity');
    }
};
