<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        $isCreate = $schema->getRecord() === null || !$schema->getRecord()->exists;

        if ($isCreate) {
            return $schema
                ->components([
                    Select::make('target_shops')
                        ->label('Target Stores')
                        ->multiple()
                        ->options(\App\Models\Shop::pluck('shop_domain', 'id')->toArray())
                        ->required(),
                    TextInput::make('title')
                        ->required(),
                    TextInput::make('vendor')
                        ->placeholder('e.g. Nike'),
                    TextInput::make('product_type')
                        ->label('Product Type')
                        ->placeholder('e.g. Apparel > Shirts')
                        ->datalist(function() {
                            return \App\Models\ProductCategory::pluck('name')->toArray();
                        }),
                    TextInput::make('handle')
                        ->placeholder('e.g. nike-shoes'),
                    Select::make('status')
                        ->options([
                            'active' => 'Active',
                            'draft' => 'Draft',
                            'archived' => 'Archived',
                        ])
                        ->required()
                        ->default('active'),
                    TextInput::make('upi_code')
                        ->label('UPI Code')
                        ->rules(['nullable', 'min:4', 'max:15', 'regex:/^[a-zA-Z0-9]+$/']),
                    Select::make('upi_status')
                        ->label('UPI Status')
                        ->options([
                            'Active' => 'Active',
                            'Pending Review' => 'Pending Review',
                            'Deprecated' => 'Deprecated',
                        ])
                        ->default('Active'),
                    TextInput::make('item_category')
                        ->label('Item Category'),
                ]);
        }

        return $schema
            ->components([
                Select::make('shop_id')
                    ->relationship('shop', 'shop_domain')
                    ->disabled()
                    ->required(),
                TextInput::make('shopify_product_id')
                    ->disabled()
                    ->required(),
                TextInput::make('title')
                    ->required(),
                TextInput::make('vendor'),
                TextInput::make('product_type')
                    ->label('Product Type')
                    ->datalist(function() {
                        return \App\Models\ProductCategory::pluck('name')->toArray();
                    }),
                TextInput::make('handle'),
                Select::make('status')
                    ->options([
                        'active' => 'Active',
                        'draft' => 'Draft',
                        'archived' => 'Archived',
                    ])
                    ->required(),
                TextInput::make('upi_code')
                    ->rules(['nullable', 'min:4', 'max:15', 'regex:/^[a-zA-Z0-9]+$/']),
                Select::make('upi_status')
                    ->options([
                        'Active' => 'Active',
                        'Pending Review' => 'Pending Review',
                        'Deprecated' => 'Deprecated',
                    ]),
                TextInput::make('item_category')
                    ->label('Item Category'),
                TextInput::make('sync_status')
                    ->disabled(),
            ]);
    }
}
