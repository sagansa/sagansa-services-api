<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportPayment extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'float',
        'paid_at' => 'date:Y-m-d',
    ];

    public function importPurchase()
    {
        return $this->belongsTo(ImportPurchase::class);
    }
}
