<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Alter column to be nullable
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('shopify_product_id')->nullable()->change();
        });

        // 2. Now that the column is nullable, update any '0' values to NULL
        DB::table('products')->where('shopify_product_id', 0)->update(['shopify_product_id' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Change any NULL values back to 0 before reverting the column definition
        DB::table('products')->whereNull('shopify_product_id')->update(['shopify_product_id' => 0]);

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('shopify_product_id')->nullable(false)->change();
        });
    }
};
