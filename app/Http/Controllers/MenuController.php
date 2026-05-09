<?php

namespace App\Http\Controllers;

use App\Enums\FoodCategory;
use App\Models\Food;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class MenuController extends Controller
{
    /**
     * @var array<int, array{key: string, label: string}>
     */
    private const PROPERTY_FILTERS = [
        ['key' => 'vegetarian', 'label' => 'Vegetarian'],
        ['key' => 'tourist_favorite', 'label' => 'Tourist favorite'],
        ['key' => 'adventurous', 'label' => 'Adventurous'],
        ['key' => 'healthy', 'label' => 'Healthy'],
        ['key' => 'quick_meal', 'label' => 'Quick meal'],
        ['key' => 'heavy_meal', 'label' => 'Heavy meal'],
        ['key' => 'shareable', 'label' => 'Shareable'],
        ['key' => 'contains_pork', 'label' => 'Pork'],
        ['key' => 'contains_beef', 'label' => 'Beef'],
        ['key' => 'contains_seafood', 'label' => 'Seafood'],
        ['key' => 'contains_nuts', 'label' => 'Nuts'],
        ['key' => 'contains_dairy', 'label' => 'Dairy'],
    ];

    public function __invoke(): Response
    {
        $foods = Food::query()
            ->availableForMenu()
            ->get();

        $counts = $foods->countBy(
            fn (Food $food): string => $food->category->value,
        );

        return Inertia::render('menu', [
            'categories' => collect(FoodCategory::cases())
                ->map(fn (FoodCategory $category): array => [
                    'key' => $category->value,
                    'label' => $category->label(),
                    'count' => $counts->get($category->value, 0),
                ])
                ->values(),
            'propertyFilters' => collect(self::PROPERTY_FILTERS)
                ->map(fn (array $filter): array => [
                    ...$filter,
                    'count' => $foods->filter(
                        fn (Food $food): bool => (bool) $food->getAttribute($filter['key']),
                    )->count(),
                ])
                ->filter(fn (array $filter): bool => $filter['count'] > 0)
                ->values(),
            'foods' => $foods
                ->map(fn (Food $food): array => [
                    'id' => $food->id,
                    'name' => $food->name,
                    'vietnamese_name' => $food->vietnamese_name,
                    'slug' => $food->slug,
                    'category' => $food->category->value,
                    'category_label' => $food->category->label(),
                    'ingredients' => $food->ingredients ?? '',
                    'vietnamese_description' => $food->vietnamese_description,
                    'formatted_price' => number_format($food->price_vnd).' VND',
                    'price_vnd' => $food->price_vnd,
                    'image_url' => Storage::disk('public')->url($food->image_path),
                    'property_keys' => $this->propertyKeysFor($food),
                    'property_labels' => $this->propertyLabelsFor($food),
                ])
                ->values(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function propertyKeysFor(Food $food): array
    {
        return collect(self::PROPERTY_FILTERS)
            ->filter(fn (array $filter): bool => (bool) $food->getAttribute($filter['key']))
            ->pluck('key')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function propertyLabelsFor(Food $food): array
    {
        return collect(self::PROPERTY_FILTERS)
            ->filter(fn (array $filter): bool => (bool) $food->getAttribute($filter['key']))
            ->pluck('label')
            ->values()
            ->all();
    }
}
