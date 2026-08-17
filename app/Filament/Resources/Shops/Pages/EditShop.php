<?php

namespace App\Filament\Resources\Shops\Pages;

use App\Filament\Resources\Shops\ShopResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditShop extends EditRecord
{
    protected static string $resource = ShopResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $shop = $this->record;
        if ($shop->access_token && $shop->status === 'active') {
            $isMock = $shop->access_token === 'mock_access_token_123456789' || str_starts_with($shop->access_token, 'mock');
            \App\Jobs\StoreProvisioningJob::dispatch($shop, $isMock);
        }
    }
}
