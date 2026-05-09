<?php

namespace Database\Factories;

use App\Enums\FoodCategory;
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
                ? 'Toasted bun, crisp vegetables, house sauce'
                : 'Fresh fruit, ice, light syrup',
            'price_vnd' => $this->faker->numberBetween(25000, 99000),
            'image_path' => 'menu/'.$category->value.'-sample.png',
            'is_available' => true,
            'sort_order' => $this->faker->numberBetween(1, 20),
        ];
    }
}
