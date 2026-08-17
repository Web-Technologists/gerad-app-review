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
        Schema::table('products', function (Blueprint $table) {
            $table->string('upi_status')->nullable()->after('upi_code');
            $table->string('last_updated_by')->nullable()->after('upi_status');
            $table->timestamp('last_updated_at')->nullable()->after('last_updated_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['upi_status', 'last_updated_by', 'last_updated_at']);
        });
    }
};
