<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $product = $this->record;
        
        $product->update([
            'last_updated_by' => auth()->user()?->name ?? 'Filament Admin',
            'last_updated_at' => now(),
            'sync_status' => 'pending_push',
        ]);

        \App\Jobs\PushUpiToShopifyJob::dispatch($product);
    }
}
