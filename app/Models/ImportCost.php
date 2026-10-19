<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ImportCost extends Model
{
    protected $connection = 'mysql';
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'tax_rate' => 'float',
        'is_foreign_currency' => 'boolean',
        'amount_foreign' => 'float',
        'amount_estimate' => 'float',
        'amount_real' => 'float',
    ];

    public function importPurchase()
    {
        return $this->belongsTo(ImportPurchase::class);
    }
}
