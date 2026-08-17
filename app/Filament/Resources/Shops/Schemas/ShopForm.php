<?php

namespace App\Filament\Resources\Shops\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class ShopForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('shop_domain')
                    ->required()
                    ->placeholder('store-name.myshopify.com')
                    ->unique(ignoreRecord: true),
                TextInput::make('custom_domain'),
                TextInput::make('access_token')
                    ->password()
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $context): bool => $context === 'create'),
                TextInput::make('status')
                    ->required()
                    ->default('active'),
            ]);
    }
}
