<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ImportPurchase extends Model
{
    protected $connection = 'mysql';
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'exchange_rate' => 'float',
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
