<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportNotul extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $guarded = [];

    protected $casts = [
        'notul_date' => 'date:Y-m-d',
        'pph_amount' => 'float',
        'ppn_amount' => 'float',
        'penalty_amount' => 'float',
        'other_amount' => 'float',
    ];

    public function importPurchase()
    {
        return $this->belongsTo(ImportPurchase::class);
    }
}
