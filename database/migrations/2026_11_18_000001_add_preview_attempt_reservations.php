<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_action_attempts', function (Blueprint $table) {
            $table->string('attempt_key', 64)->primary();
            $table->string('action_key', 200)->index();
            $table->string('operation', 80);
            $table->timestamp('created_at');
        });
        Schema::table('room_preview_generations', function (Blueprint $table) {
            $table->string('attempt_token', 36)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('room_preview_generations', fn (Blueprint $table) => $table->dropColumn('attempt_token'));
        Schema::dropIfExists('ai_action_attempts');
    }
};
