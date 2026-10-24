<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SOP (Standard Operation Procedure) terstruktur: judul, deskripsi, cover,
 * dan langkah-langkah bergambar (SopStep). Hanya yang published disajikan
 * ke mobile; `revision` naik otomatis saat admin mengubah isi.
 */
class Sop extends Model
{
    use HasFactory;

    protected $connection = 'mysql';
    protected $table = 'sops';
    protected $guarded = [];

    protected $casts = [
        'is_published' => 'boolean',
        'sort_order' => 'integer',
        'revision' => 'integer',
        'published_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(SopCategory::class, 'sop_category_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(SopStep::class, 'sop_id')->orderBy('sort_order');
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }
}
