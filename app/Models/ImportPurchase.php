<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportPurchase extends Model
{
    protected $connection = 'mysql';

    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'exchange_rate' => 'float',
        'usd_vnd_rate' => 'float',
        'purchase_date' => 'date:Y-m-d',
        'total_estimate' => 'float',
        'total_real' => 'float',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function items()
    {
        return $this->hasMany(ImportItem::class);
    }

    public function costs()
    {
        return $this->hasMany(ImportCost::class);
    }

    public function payments()
    {
        return $this->hasMany(ImportPayment::class)->orderBy('paid_at')->orderBy('id');
    }

    public function notuls()
    {
        return $this->hasMany(ImportNotul::class)->orderBy('notul_date')->orderBy('id');
    }

    public function documents()
    {
        return $this->hasMany(ImportDocument::class);
    }

    /**
     * Tipe dokumen yang sudah ter-upload (unik) — dipakai gerbang tahap.
     */
    public function uploadedDocumentTypes(): array
    {
        return $this->documents()
            ->pluck('type')
            ->unique()
            ->values()
            ->all();
    }
}
