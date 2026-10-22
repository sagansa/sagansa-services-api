<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Kurs referensi antar valuta per tanggal (mis. 1 USD = x VND).
 *
 * `source` = `public` (cache hasil referensi publik; rate 0 menandai
 * "pernah dicek, tidak tersedia") atau `manual` (input admin — menimpa
 * publik untuk tanggal itu).
 */
class CurrencyRate extends Model
{
    protected $connection = 'mysql';

    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'rate' => 'float',
        'rate_date' => 'date:Y-m-d',
    ];
}
