<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency key untuk pembuatan sales order online (for=3).
 *
 * Mobile mengirim UUID per sesi form; saat retry setelah timeout, key yang
 * sama membuat backend mengembalikan order existing alih-alih INSERT baru
 * (akar masalah double order). Unique index pada key aman terhadap
 * soft-delete karena key memang tidak boleh dipakai dua kali.
 *
 * CATATAN: sengaja TIDAK menambah unique index pada receipt_no — resi sama
 * antar periode adalah hal legitimate (provider daur ulang nomor resi).
 * Pencegahan resi duplikat dilakukan app-level dengan window waktu
 * (lihat SalesOrderController::storeOnline + config/sales-order.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_orders') || Schema::hasColumn('sales_orders', 'idempotency_key')) {
            return;
        }

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('receipt_no');
            $table->unique('idempotency_key', 'sales_orders_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales_orders') || ! Schema::hasColumn('sales_orders', 'idempotency_key')) {
            return;
        }

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropUnique('sales_orders_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
