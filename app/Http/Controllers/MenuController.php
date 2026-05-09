<?php

namespace App\Http\Controllers;

use App\Enums\FoodCategory;
use App\MenuFilterDefinitions;
use App\Models\Food;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class MenuController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $language = $request->string('language')->toString() === 'en' ? 'en' : 'vi';
        $foods = Food::query()
            ->availableForMenu()
            ->get();

        $counts = $foods->countBy(
            fn (Food $food): string => $food->category->value,
        );

        return Inertia::render('menu', [
            'language' => $language,
            'categories' => collect(FoodCategory::cases())
                ->map(fn (FoodCategory $category): array => [
                    'key' => $category->value,
                    'labels' => $category->labels(),
                    'count' => $counts->get($category->value, 0),
                ])
                ->values(),
            'propertyFilters' => collect(MenuFilterDefinitions::propertyFilters())
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
                    'category_labels' => $food->category->labels(),
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
        return collect(MenuFilterDefinitions::propertyFilters())
            ->filter(fn (array $filter): bool => (bool) $food->getAttribute($filter['key']))
            ->pluck('key')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{en: string, vi: string}>
     */
    private function propertyLabelsFor(Food $food): array
    {
        return collect(MenuFilterDefinitions::propertyFilters())
            ->filter(fn (array $filter): bool => (bool) $food->getAttribute($filter['key']))
            ->pluck('labels')
            ->values()
            ->all();
    }
}
