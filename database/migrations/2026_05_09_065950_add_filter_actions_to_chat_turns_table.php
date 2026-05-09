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
        Schema::table('chat_turns', function (Blueprint $table) {
            $table->json('filter_context')->nullable()->after('cart_context');
            $table->json('filter_action')->nullable()->after('cart_actions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_turns', function (Blueprint $table) {
            $table->dropColumn(['filter_context', 'filter_action']);
        });
    }
};
