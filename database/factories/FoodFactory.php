<?php

namespace Database\Factories;

use App\Enums\FoodCategory;
use App\Enums\FoodTaste;
use App\Models\Food;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Food>
 */
class FoodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->words(3, true);
        $category = $this->faker->randomElement(FoodCategory::cases());

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'category' => $category,
            'ingredients' => $category === FoodCategory::Food
                ? [
                    ['name' => 'Rice noodles', 'vietnamese_name' => 'Bánh phở', 'quantity_grams' => 180],
                    ['name' => 'Beef', 'vietnamese_name' => 'Thịt bò', 'quantity_grams' => 90],
                    ['name' => 'Fresh herbs', 'vietnamese_name' => 'Rau thơm', 'quantity_grams' => 20],
                ]
                : [
                    ['name' => 'Prepared drink', 'vietnamese_name' => 'Phần đồ uống', 'quantity_grams' => 250],
                    ['name' => 'Ice', 'vietnamese_name' => 'Đá', 'quantity_grams' => 120],
                ],
            'taste' => $category === FoodCategory::Drink ? FoodTaste::Sweet : FoodTaste::Normal,
            'how_made' => $category === FoodCategory::Food
                ? 'Ingredients are prepared to order and assembled with fresh herbs and sauce.'
                : 'The drink is mixed to order and served cold with ice.',
            'vietnamese_how_made' => $category === FoodCategory::Food
                ? 'Nguyên liệu được chuẩn bị theo phần và dùng cùng rau thơm, nước chấm.'
                : 'Đồ uống được pha theo từng ly và phục vụ lạnh với đá.',
            'price_vnd' => $this->faker->numberBetween(25000, 99000),
            'image_path' => 'menu/'.$category->value.'-sample.png',
            'is_available' => true,
            'sort_order' => $this->faker->numberBetween(1, 20),
        ];
    }
}
