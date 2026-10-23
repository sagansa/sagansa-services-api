<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ImportItem extends Model
{
    protected $connection = 'mysql';
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'float',
        'unit_price_foreign' => 'float',
        'quantity_received' => 'float',
        'hpp_estimate' => 'float',
        'hpp_real' => 'float',
        'selling_price' => 'float',
    ];

    public function importPurchase()
    {
        return $this->belongsTo(ImportPurchase::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
