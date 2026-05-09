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
            'is_available' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
