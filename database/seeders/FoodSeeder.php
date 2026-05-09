<?php

namespace Database\Seeders;

use App\Enums\FoodCategory;
use App\Models\Food;
use Illuminate\Database\Seeder;

class FoodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        collect([
            [
                'name' => 'Crispy Rice Chicken',
                'category' => FoodCategory::Food,
                'ingredients' => 'Crisp chicken, steamed rice cake, pickled cucumber, chili mayo',
                'price_vnd' => 79000,
                'image_path' => 'menu/crispy-rice-chicken.png',
            ],
            [
                'name' => 'Double Sizzle Burger',
                'category' => FoodCategory::Food,
                'ingredients' => 'Two grilled patties, cheddar, lettuce, tomato, tangy house sauce',
                'price_vnd' => 99000,
                'image_path' => 'menu/double-sizzle-burger.png',
            ],
            [
                'name' => 'Golden Fries Cup',
                'category' => FoodCategory::Food,
                'ingredients' => 'Crispy potato fries, sea salt, roasted garlic dip',
                'price_vnd' => 39000,
                'image_path' => 'menu/golden-fries-cup.png',
            ],
            [
                'name' => 'Chili Lime Nuggets',
                'category' => FoodCategory::Food,
                'ingredients' => 'Chicken bites, chili lime dust, sweet tomato dip',
                'price_vnd' => 59000,
                'image_path' => 'menu/chili-lime-nuggets.png',
            ],
            [
                'name' => 'Garden Egg Wrap',
                'category' => FoodCategory::Food,
                'ingredients' => 'Soft egg, herb greens, roasted corn, creamy pepper sauce',
                'price_vnd' => 65000,
                'image_path' => 'menu/garden-egg-wrap.png',
            ],
            [
                'name' => 'Iced Citrus Tea',
                'category' => FoodCategory::Drink,
                'ingredients' => 'Black tea, orange, lemon, mint, ice',
                'price_vnd' => 35000,
                'image_path' => 'menu/iced-citrus-tea.png',
            ],
            [
                'name' => 'Berry Fizz',
                'category' => FoodCategory::Drink,
                'ingredients' => 'Strawberry, raspberry, sparkling soda, lime',
                'price_vnd' => 42000,
                'image_path' => 'menu/berry-fizz.png',
            ],
            [
                'name' => 'Vietnamese Iced Coffee',
                'category' => FoodCategory::Drink,
                'ingredients' => 'Dark roast coffee, condensed milk, ice',
                'price_vnd' => 45000,
                'image_path' => 'menu/vietnamese-iced-coffee.png',
            ],
            [
                'name' => 'Mango Yogurt Shake',
                'category' => FoodCategory::Drink,
                'ingredients' => 'Mango, yogurt, milk, honey, ice',
                'price_vnd' => 52000,
                'image_path' => 'menu/mango-yogurt-shake.png',
            ],
            [
                'name' => 'Sparkling Lychee',
                'category' => FoodCategory::Drink,
                'ingredients' => 'Lychee, soda, basil seed, lime',
                'price_vnd' => 46000,
                'image_path' => 'menu/sparkling-lychee.png',
            ],
        ])->each(function (array $food, int $index): void {
            Food::updateOrCreate(
                ['slug' => str($food['name'])->slug()->toString()],
                [
                    ...$food,
                    'is_available' => true,
                    'sort_order' => $index + 1,
                ],
            );
        });
    }
}
