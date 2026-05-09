<?php

namespace App\Filament\Resources\Food;

use App\Enums\FoodCategory;
use App\Enums\FoodTaste;
use App\Filament\Resources\Food\Pages\CreateFood;
use App\Filament\Resources\Food\Pages\EditFood;
use App\Filament\Resources\Food\Pages\ListFood;
use App\Models\Food;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class FoodResource extends Resource
{
    protected static ?string $model = Food::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $modelLabel = 'menu item';

    protected static ?string $pluralModelLabel = 'menu items';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, callable $set): void {
                        if (filled($state)) {
                            $set('slug', Str::slug($state));
                        }
                    }),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Select::make('category')
                    ->options(FoodCategory::class)
                    ->required()
                    ->native(false),
                Textarea::make('ingredients')
                    ->label('Ingredients JSON')
                    ->rows(4)
                    ->formatStateUsing(fn (mixed $state): string => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : (string) ($state ?? '[]'))
                    ->dehydrateStateUsing(function (?string $state): array {
                        $ingredients = json_decode($state ?: '[]', true);

                        return is_array($ingredients) ? $ingredients : [];
                    })
                    ->columnSpanFull(),
                Select::make('taste')
                    ->options(FoodTaste::class)
                    ->default(FoodTaste::Normal->value)
                    ->required()
                    ->native(false),
                Textarea::make('how_made')
                    ->label('How made')
                    ->rows(3)
                    ->columnSpanFull(),
                Textarea::make('vietnamese_how_made')
                    ->label('Vietnamese how made')
                    ->rows(3)
                    ->columnSpanFull(),
                TextInput::make('price_vnd')
                    ->label('Price')
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->prefix('VND'),
                FileUpload::make('image_path')
                    ->label('Image')
                    ->required()
                    ->disk('public')
                    ->directory('menu')
                    ->visibility('public')
                    ->image()
                    ->imageEditor()
                    ->maxSize(2048)
                    ->columnSpanFull(),
                Toggle::make('is_available')
                    ->label('Available')
                    ->default(true),
                TextInput::make('sort_order')
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->default(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                ImageColumn::make('image_path')
                    ->label('Image')
                    ->disk('public')
                    ->visibility('public')
                    ->square()
                    ->defaultImageUrl(url('/favicon.svg')),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->badge()
                    ->formatStateUsing(fn (FoodCategory $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('price_vnd')
                    ->label('Price')
                    ->formatStateUsing(fn (int $state): string => number_format($state).' VND')
                    ->sortable(),
                IconColumn::make('is_available')
                    ->label('Available')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(FoodCategory::class),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->reorderable('sort_order')
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFood::route('/'),
            'create' => CreateFood::route('/create'),
            'edit' => EditFood::route('/{record}/edit'),
        ];
    }
}
