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
        $columns = array_values(array_filter(
            ['beginner_friendly', 'comfort_food'],
            fn (string $column): bool => Schema::hasColumn('foods', $column),
        ));

        if ($columns === []) {
            return;
        }

        Schema::table('foods', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $needsBeginnerFriendly = ! Schema::hasColumn('foods', 'beginner_friendly');
        $needsComfortFood = ! Schema::hasColumn('foods', 'comfort_food');

        if (! $needsBeginnerFriendly && ! $needsComfortFood) {
            return;
        }

        Schema::table('foods', function (Blueprint $table) use ($needsBeginnerFriendly, $needsComfortFood) {
            if ($needsBeginnerFriendly) {
                $table->boolean('beginner_friendly')->default(false);
            }

            if ($needsComfortFood) {
                $table->boolean('comfort_food')->default(false);
            }
        });
    }
};
