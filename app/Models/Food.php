<?php

namespace App\Models;

use App\Enums\FoodCategory;
use Database\Factories\FoodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Table('foods')]
#[Fillable([
    'name',
    'slug',
    'category',
    'ingredients',
    'menu_code',
    'vietnamese_name',
    'description',
    'vietnamese_description',
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
    'price_vnd',
    'image_path',
    'is_available',
    'sort_order',
])]
class Food extends Model
{
    /** @use HasFactory<FoodFactory> */
    use HasFactory;

    protected $attributes = [
        'category' => 'food',
        'is_available' => true,
        'sort_order' => 0,
    ];

    /**
     * @param  Builder<Food>  $query
     * @return Builder<Food>
     */
    public function scopeAvailableForMenu(Builder $query): Builder
    {
        return $query
            ->where('is_available', true)
            ->orderByRaw(
                'case category when ? then 0 when ? then 1 else 2 end',
                [FoodCategory::Food->value, FoodCategory::Drink->value],
            )
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => FoodCategory::class,
            'price_vnd' => 'integer',
            'sweetness' => 'integer',
            'spiciness' => 'integer',
            'sourness' => 'integer',
            'saltiness' => 'integer',
            'richness' => 'integer',
            'vegetarian' => 'boolean',
            'contains_pork' => 'boolean',
            'contains_beef' => 'boolean',
            'contains_seafood' => 'boolean',
            'contains_nuts' => 'boolean',
            'contains_dairy' => 'boolean',
            'halal_friendly' => 'boolean',
            'beginner_friendly' => 'boolean',
            'tourist_favorite' => 'boolean',
            'adventurous' => 'boolean',
            'healthy' => 'boolean',
            'comfort_food' => 'boolean',
            'quick_meal' => 'boolean',
            'heavy_meal' => 'boolean',
            'shareable' => 'boolean',
            'is_available' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
