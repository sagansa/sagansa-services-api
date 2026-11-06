<?php

/**
 * Konfigurasi sales order.
 */
return [
    // Window cek resi duplikat pada pembuatan order online (for=3):
    // resi sama dalam N hari terakhir dianggap duplikat (ditolak 409,
    // bisa di-override mobile). Di luar window resi sama legitimate —
    // provider marketplace bisa daur ulang nomor resi.
    'duplicate_receipt_window_days' => env('SALES_ORDER_DUPLICATE_RECEIPT_DAYS', 31),
];
