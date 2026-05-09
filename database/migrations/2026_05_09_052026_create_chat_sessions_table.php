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
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('laravel_session_id')->unique();
            $table->string('acp_session_id')->nullable()->unique();
            $table->string('status')->default('pending')->index();
            $table->timestamp('warmed_at')->nullable();
            $table->timestamp('last_used_at')->nullable()->index();
            $table->text('error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_sessions');
    }
};
