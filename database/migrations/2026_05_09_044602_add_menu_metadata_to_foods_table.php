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
        Schema::table('foods', function (Blueprint $table) {
            $table->string('menu_code')->nullable()->unique();
            $table->string('vietnamese_name')->nullable();
            $table->text('description')->nullable();
            $table->string('subcategory')->nullable()->index();
            $table->string('protein')->nullable();
            $table->string('cooking_style')->nullable();
            $table->unsignedTinyInteger('sweetness')->default(0);
            $table->unsignedTinyInteger('spiciness')->default(0);
            $table->unsignedTinyInteger('sourness')->default(0);
            $table->unsignedTinyInteger('saltiness')->default(0);
            $table->unsignedTinyInteger('richness')->default(0);
            $table->string('temperature')->nullable()->index();
            $table->string('texture')->nullable();
            $table->boolean('vegetarian')->default(false);
            $table->boolean('contains_pork')->default(false);
            $table->boolean('contains_beef')->default(false);
            $table->boolean('contains_seafood')->default(false);
            $table->boolean('contains_nuts')->default(false);
            $table->boolean('contains_dairy')->default(false);
            $table->boolean('halal_friendly')->default(false);
            $table->boolean('beginner_friendly')->default(false);
            $table->boolean('tourist_favorite')->default(false);
            $table->boolean('adventurous')->default(false);
            $table->boolean('healthy')->default(false);
            $table->boolean('comfort_food')->default(false);
            $table->boolean('quick_meal')->default(false);
            $table->boolean('heavy_meal')->default(false);
            $table->boolean('shareable')->default(false);
            $table->string('price_range')->nullable()->index();
            $table->string('best_time')->nullable()->index();
            $table->text('keywords')->nullable();
            $table->text('recommendation_reason')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('foods', function (Blueprint $table) {
            $table->dropUnique('foods_menu_code_unique');
            $table->dropIndex('foods_subcategory_index');
            $table->dropIndex('foods_temperature_index');
            $table->dropIndex('foods_price_range_index');
            $table->dropIndex('foods_best_time_index');
        });

        Schema::table('foods', function (Blueprint $table) {
            $table->dropColumn([
                'menu_code',
                'vietnamese_name',
                'description',
                'subcategory',
                'protein',
                'cooking_style',
                'sweetness',
                'spiciness',
                'sourness',
                'saltiness',
                'richness',
                'temperature',
                'texture',
                'vegetarian',
                'contains_pork',
                'contains_beef',
                'contains_seafood',
                'contains_nuts',
                'contains_dairy',
                'halal_friendly',
                'beginner_friendly',
                'tourist_favorite',
                'adventurous',
                'healthy',
                'comfort_food',
                'quick_meal',
                'heavy_meal',
                'shareable',
                'price_range',
                'best_time',
                'keywords',
                'recommendation_reason',
            ]);
        });
    }
};
