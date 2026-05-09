<?php

use App\Enums\FoodTaste;
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
        Schema::table('foods', function (Blueprint $table) {
            $table->enum('taste', array_column(FoodTaste::cases(), 'value'))
                ->default(FoodTaste::Normal->value)
                ->after('vietnamese_description');
            $table->text('how_made')->nullable()->after('taste');
            $table->text('vietnamese_how_made')->nullable()->after('how_made');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('foods', function (Blueprint $table) {
            $table->dropColumn([
                'taste',
                'how_made',
                'vietnamese_how_made',
            ]);
        });
    }
};
