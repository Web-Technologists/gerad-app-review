<?php

namespace App\Filament\Widgets;

use App\Models\Shop;
use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        $totalStores = Shop::count();
        $totalProducts = Product::count();
        $missingUpi = Product::whereNull('upi_code')->count();
        
        $syncedCount = Product::where('sync_status', 'synced')->count();
        $syncedRate = $totalProducts > 0 ? round(($syncedCount / $totalProducts) * 100) : 100;

        return [
            Stat::make('Connected Stores', $totalStores)
                ->description('Total Shopify store connections')
                ->descriptionIcon('heroicon-m-building-office-2')
                ->color('info'),
            Stat::make('Total Products Synced', $totalProducts)
                ->description('Imported from Shopify catalogs')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('success'),
            Stat::make('Missing UPI Codes', $missingUpi)
                ->description('Products without an assigned UPI')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($missingUpi > 0 ? 'warning' : 'success'),
            Stat::make('Sync Integrity Rate', "{$syncedRate}%")
                ->description('Percentage of successfully synced products')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color($syncedRate > 90 ? 'success' : 'danger'),
        ];
    }
}
