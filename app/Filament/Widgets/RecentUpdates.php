<?php

namespace App\Filament\Widgets;

use App\Models\Product;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentUpdates extends BaseWidget
{
    protected static ?int $sort = 2;
    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = 'Recently Synced UPI Actions';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Product::whereNotNull('upi_code')
                    ->orderBy('last_updated_at', 'desc')
                    ->limit(5)
            )
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Product Title')
                    ->limit(50)
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('upi_code')
                    ->label('UPI Code')
                    ->fontFamily('mono')
                    ->copyable()
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('upi_status')
                    ->label('UPI Status')
                    ->badge()
                    ->color(fn (string $state): string => match (strtolower($state)) {
                        'active' => 'success',
                        'pending review' => 'warning',
                        'deprecated' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('shop.shop_domain')
                    ->label('Shop Domain'),
                Tables\Columns\TextColumn::make('last_updated_by')
                    ->label('Updated By'),
                Tables\Columns\TextColumn::make('last_updated_at')
                    ->label('Updated At')
                    ->dateTime()
                    ->since(),
            ]);
    }
}
