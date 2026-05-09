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
            ['vegetarian', 'contains_dairy'],
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
        $needsVegetarian = ! Schema::hasColumn('foods', 'vegetarian');
        $needsContainsDairy = ! Schema::hasColumn('foods', 'contains_dairy');

        if (! $needsVegetarian && ! $needsContainsDairy) {
            return;
        }

        Schema::table('foods', function (Blueprint $table) use ($needsVegetarian, $needsContainsDairy) {
            if ($needsVegetarian) {
                $table->boolean('vegetarian')->default(false);
            }

            if ($needsContainsDairy) {
                $table->boolean('contains_dairy')->default(false);
            }
        });
    }
};
