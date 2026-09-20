<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model ini dipetakan ke tabel `stock_cards` dengan scope
 * `for = 'remaining_storage'`. Merupakan sumber laporan stok sisa
 * gudang yang dibaca oleh stock monitoring & panel admin.
 */
class RemainingStorage extends Model
{
    use HasFactory;

    protected $connection = 'mysql';
    protected $table = 'stock_cards';
    protected $guarded = [];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function detailStockCards(): HasMany
    {
        return $this->hasMany(DetailStockCard::class, 'stock_card_id');
    }
}
