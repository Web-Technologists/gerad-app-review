<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'shop_id',
        'shopify_product_id',
        'handle',
        'title',
        'vendor',
        'product_type',
        'status',
        'upi_code',
        'upi_status',
        'item_category',
        'primary_licensor',
        'main_image_url',
        'last_updated_by',
        'last_updated_at',
        'metafield_id',
        'sync_status',
        'last_synced_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shopify_product_id' => 'integer',
            'metafield_id' => 'integer',
            'last_synced_at' => 'datetime',
            'last_updated_at' => 'datetime',
        ];
    }

    /**
     * Get the shop that owns the product.
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Get the variants for the product.
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }
}
