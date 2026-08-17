<?php

namespace App\Filament\Resources\Shops\Pages;

use App\Filament\Resources\Shops\ShopResource;
use Filament\Resources\Pages\CreateRecord;

class CreateShop extends CreateRecord
{
    protected static string $resource = ShopResource::class;

    protected function afterCreate(): void
    {
        $shop = $this->record;
        if ($shop->access_token && $shop->status === 'active') {
            $isMock = $shop->access_token === 'mock_access_token_123456789' || str_starts_with($shop->access_token, 'mock');
            \App\Jobs\StoreProvisioningJob::dispatch($shop, $isMock);
        }
    }
}
