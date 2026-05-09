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
        $shouldAddTurnIndex = ! Schema::hasColumn('chat_turns', 'chat_session_id');

        Schema::table('chat_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('chat_sessions', 'laravel_session_id')) {
                $table->string('laravel_session_id')->nullable()->unique();
            }

            if (! Schema::hasColumn('chat_sessions', 'acp_session_id')) {
                $table->string('acp_session_id')->nullable()->unique();
            }

            if (! Schema::hasColumn('chat_sessions', 'status')) {
                $table->string('status')->default('pending')->index();
            }

            if (! Schema::hasColumn('chat_sessions', 'warmed_at')) {
                $table->timestamp('warmed_at')->nullable();
            }

            if (! Schema::hasColumn('chat_sessions', 'last_used_at')) {
                $table->timestamp('last_used_at')->nullable()->index();
            }

            if (! Schema::hasColumn('chat_sessions', 'error')) {
                $table->text('error')->nullable();
            }

            if (! Schema::hasColumn('chat_sessions', 'metadata')) {
                $table->json('metadata')->nullable();
            }
        });

        Schema::table('chat_turns', function (Blueprint $table) {
            if (! Schema::hasColumn('chat_turns', 'chat_session_id')) {
                $table->foreignId('chat_session_id')->nullable()->constrained()->cascadeOnDelete();
            }

            if (! Schema::hasColumn('chat_turns', 'status')) {
                $table->string('status')->default('pending')->index();
            }

            if (! Schema::hasColumn('chat_turns', 'user_message')) {
                $table->text('user_message')->nullable();
            }

            if (! Schema::hasColumn('chat_turns', 'cart_context')) {
                $table->json('cart_context')->nullable();
            }

            if (! Schema::hasColumn('chat_turns', 'reply')) {
                $table->text('reply')->nullable();
            }

            if (! Schema::hasColumn('chat_turns', 'cart_actions')) {
                $table->json('cart_actions')->nullable();
            }

            if (! Schema::hasColumn('chat_turns', 'raw_response')) {
                $table->json('raw_response')->nullable();
            }

            if (! Schema::hasColumn('chat_turns', 'error')) {
                $table->text('error')->nullable();
            }

            if (! Schema::hasColumn('chat_turns', 'completed_at')) {
                $table->timestamp('completed_at')->nullable();
            }
        });

        if ($shouldAddTurnIndex) {
            Schema::table('chat_turns', function (Blueprint $table) {
                $table->index(['chat_session_id', 'status', 'created_at'], 'chat_turns_session_status_created_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
