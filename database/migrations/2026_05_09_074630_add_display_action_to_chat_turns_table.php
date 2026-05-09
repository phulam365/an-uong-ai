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
            $table->json('display_action')->nullable()->after('filter_action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_turns', function (Blueprint $table) {
            $table->dropColumn('display_action');
        });
    }
};
