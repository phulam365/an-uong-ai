<?php

namespace App;

use App\Enums\FoodCategory;

class MenuFilterDefinitions
{
    /**
     * @return array<int, array{key: string, labels: array{en: string, vi: string}}>
     */
    public static function propertyFilters(): array
    {
        return [
            ['key' => 'tourist_favorite', 'labels' => ['en' => 'Tourist favorite', 'vi' => 'Du khách yêu thích']],
            ['key' => 'adventurous', 'labels' => ['en' => 'Adventurous', 'vi' => 'Đậm vị khám phá']],
            ['key' => 'healthy', 'labels' => ['en' => 'Healthy', 'vi' => 'Thanh nhẹ']],
            ['key' => 'quick_meal', 'labels' => ['en' => 'Quick meal', 'vi' => 'Ăn nhanh']],
            ['key' => 'heavy_meal', 'labels' => ['en' => 'Heavy meal', 'vi' => 'No lâu']],
            ['key' => 'shareable', 'labels' => ['en' => 'Shareable', 'vi' => 'Dùng chung']],
            ['key' => 'contains_pork', 'labels' => ['en' => 'Pork', 'vi' => 'Có thịt heo']],
            ['key' => 'contains_beef', 'labels' => ['en' => 'Beef', 'vi' => 'Có thịt bò']],
            ['key' => 'contains_seafood', 'labels' => ['en' => 'Seafood', 'vi' => 'Có hải sản']],
            ['key' => 'contains_nuts', 'labels' => ['en' => 'Nuts', 'vi' => 'Có đậu phộng']],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function propertyKeys(): array
    {
        return collect(self::propertyFilters())
            ->pluck('key')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function categoryKeys(): array
    {
        return collect(FoodCategory::cases())
            ->map(fn (FoodCategory $category): string => $category->value)
            ->all();
    }
}
