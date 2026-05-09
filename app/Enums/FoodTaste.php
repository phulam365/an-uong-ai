<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FoodTaste: string implements HasLabel
{
    case Normal = 'normal';
    case Sweet = 'sweet';
    case Spicy = 'spicy';

    public function getLabel(): ?string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Sweet => 'Sweet',
            self::Spicy => 'Spicy',
        };
    }

    /**
     * @return array{en: string, vi: string}
     */
    public function labels(): array
    {
        return match ($this) {
            self::Normal => ['en' => 'Normal', 'vi' => 'Vị vừa'],
            self::Sweet => ['en' => 'Sweet', 'vi' => 'Ngọt'],
            self::Spicy => ['en' => 'Spicy', 'vi' => 'Cay'],
        };
    }
}
