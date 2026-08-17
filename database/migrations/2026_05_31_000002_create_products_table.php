<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->onDelete('cascade');
            $table->unsignedBigInteger('shopify_product_id')->unique();
            $table->string('title');
            $table->string('vendor');
            $table->string('product_type');
            $table->string('status')->default('active'); // active, draft, archived
            $table->string('upi_code', 100)->nullable();
            $table->unsignedBigInteger('metafield_id')->nullable();
            $table->string('sync_status')->default('synced'); // synced, pending_push, pending_pull, failed
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('upi_code');
            $table->index(['shop_id', 'vendor', 'product_type', 'status'], 'idx_products_filter');
            
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->fulltext('title', 'idx_products_title_search');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
