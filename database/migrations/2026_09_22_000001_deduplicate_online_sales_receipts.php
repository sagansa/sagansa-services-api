<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cleanup data: gabungkan baris sales_orders online (for=3) yang
 * terduplikasi karena sync marketplace-bot dulu selalu INSERT setiap
 * waybill dicetak (satu resi bisa punya banyak baris).
 *
 * Akibatnya: updateDelivery yang me-resolve order by receipt_no bisa
 * meng-update duplikat yang salah — status "Sudah Dikirim" tampak
 * berhasil lalu kembali ke "Siap Dikirim" saat order dibuka ulang
 * (lihat bug order #24278 / resi SPXID031809686263).
 *
 * Aturan merge per kelompok resi duplikat (baris hidup saja):
 * - Kanonik = baris TERMUDA (id terbesar) — konsisten dengan fallback
 *   lookup updateDelivery() dan urutan list mobile.
 * - Item (detail_sales_orders): jika kanonik tidak punya item, adopsi
 *   dari duplikat lain yang punya (lalu item itu dilepas dari duplikat
 *   asalnya agar tidak yatim).
 * - Field kosong/0 pada kanonik diisi dari duplikat lain yang berisi:
 *   image_payment, total_price, received_by, image_delivery,
 *   delivery_date, payment_proof_printed_at.
 * - delivery_status: jika kanonik masih 1/4 (belum final) tapi ada
 *   duplikat yang sudah 3 (terkirim) atau 6 (dikembalikan), kanonik
 *   mewarisi status terminal tersebut beserta field pendukungnya.
 * - Duplikat yang kalah di-soft-delete (bukan hard delete) demi jejak
 *   audit.
 *
 * CATATAN unique index: tidak ditambahkan di level DB. Index unik biasa
 * pada (receipt_no, for) akan melarang baris soft-delete (yang tetap
 * menyimpan resi), sedangkan MySQL menganggap NULL saling berbeda
 * sehingga (receipt_no, for, deleted_at) tidak menjamin keunikan baris
 * hidup. Pencegahan duplikat baru dilakukan di aplikasi:
 * MarketplaceOrderSyncController::sync sekarang idempoten, dan
 * updateDelivery memprioritaskan order_id.
 *
 * Migration ini idempoten: dijalankan ulang tidak melakukan apa-apa
 * bila tidak ada kelompok duplikat yang tersisa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sales_orders') || !Schema::hasTable('detail_sales_orders')) {
            return;
        }

        $duplicateReceipts = DB::table('sales_orders')
            ->where('for', 3)
            ->whereNull('deleted_at')
            ->whereNotNull('receipt_no')
            ->where('receipt_no', '!=', '')
            ->select('receipt_no', DB::raw('COUNT(*) as n'))
            ->groupBy('receipt_no')
            ->having('n', '>', 1)
            ->pluck('receipt_no');

        foreach ($duplicateReceipts as $receiptNo) {
            $rows = DB::table('sales_orders')
                ->where('for', 3)
                ->where('receipt_no', $receiptNo)
                ->whereNull('deleted_at')
                ->orderByDesc('id')
                ->get();

            if ($rows->count() < 2) {
                continue;
            }

            $canonical = $rows->shift(); // termuda
            $others = $rows;

            $update = [];

            // Warisi status terminal (3/6) dari duplikat mana pun bila
            // kanonik belum final — staff mungkin sudah meng-update
            // duplikat lain sebelum cleanup ini berjalan.
            $terminalColumns = ['received_by', 'image_delivery', 'delivery_date'];
            if (in_array((int) $canonical->delivery_status, [1, 4], true)) {
                $terminal = $others->first(fn ($r) => in_array((int) $r->delivery_status, [3, 6], true));
                if ($terminal) {
                    $update['delivery_status'] = $terminal->delivery_status;
                    foreach ($terminalColumns as $col) {
                        if (blank($canonical->{$col} ?? null) && filled($terminal->{$col} ?? null)) {
                            $update[$col] = $terminal->{$col};
                        }
                    }
                }
            }

            // Isi field kosong/0 pada kanonik dari duplikat lain.
            foreach (['image_payment', 'payment_proof_printed_at'] as $col) {
                if (!Schema::hasColumn('sales_orders', $col)) {
                    continue;
                }
                if (blank($canonical->{$col} ?? null)) {
                    $donor = $others->first(fn ($r) => filled($r->{$col} ?? null));
                    if ($donor) {
                        $update[$col] = $donor->{$col};
                    }
                }
            }
            if ((float) ($canonical->total_price ?? 0) === 0.0) {
                $donor = $others->first(fn ($r) => (float) ($r->total_price ?? 0) > 0.0);
                if ($donor) {
                    $update['total_price'] = $donor->total_price;
                }
            }

            // Item: adopsi dari duplikat lain bila kanonik kosong.
            $canonicalHasItems = DB::table('detail_sales_orders')
                ->where('sales_order_id', $canonical->id)
                ->exists();
            if (!$canonicalHasItems) {
                $donor = $others->first(fn ($r) => DB::table('detail_sales_orders')
                    ->where('sales_order_id', $r->id)
                    ->exists());
                if ($donor) {
                    DB::table('detail_sales_orders')
                        ->where('sales_order_id', $donor->id)
                        ->update(['sales_order_id' => $canonical->id]);
                }
            }

            if (!empty($update)) {
                $update['updated_at'] = now();
                DB::table('sales_orders')->where('id', $canonical->id)->update($update);
            }

            // Soft-delete duplikat yang kalah.
            DB::table('sales_orders')
                ->whereIn('id', $others->pluck('id'))
                ->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Data cleanup satu arah — tidak dipulihkan otomatis. Duplikat
        // yang di-soft-delete tetap bisa direstorasi manual bila perlu.
    }
};
