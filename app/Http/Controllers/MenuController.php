<?php

namespace App\Http\Controllers;

use App\Enums\FoodCategory;
use App\Models\Food;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class MenuController extends Controller
{
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
            'foods' => $foods
                ->map(fn (Food $food): array => [
                    'id' => $food->id,
                    'name' => $food->name,
                    'slug' => $food->slug,
                    'category' => $food->category->value,
                    'category_label' => $food->category->label(),
                    'ingredients' => $food->ingredients ?? '',
                    'formatted_price' => number_format($food->price_vnd).' VND',
                    'price_vnd' => $food->price_vnd,
                    'image_url' => Storage::disk('public')->url($food->image_path),
                ])
                ->values(),
        ]);
    }
}
