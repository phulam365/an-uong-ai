<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FoodCategory: string implements HasLabel
{
    case Food = 'food';
    case Drink = 'drink';

    public function getLabel(): ?string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Food => 'Food',
            self::Drink => 'Drink',
        };
    }
}
