<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('phase', 10)->default('lobby');
            $table->unsignedInteger('round')->default(0);
            $table->unsignedBigInteger('ends_at')->default(0);
            $table->json('prompts');
            $table->timestamps();
        });

        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64);
            $table->string('nick', 20);
            $table->string('avatar', 20)->nullable();
            $table->timestamps();
            $table->unique(['game_id', 'token']);
        });

        Schema::create('answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('round');
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('text', 200);
            $table->timestamps();
            $table->unique(['game_id', 'round', 'player_id']);
        });

        Schema::create('votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('round');
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('answer_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['game_id', 'round', 'player_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('votes');
        Schema::dropIfExists('answers');
        Schema::dropIfExists('players');
        Schema::dropIfExists('games');
        Schema::dropIfExists('sessions');
    }
};
