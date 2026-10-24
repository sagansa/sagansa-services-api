<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu langkah SOP: judul, deskripsi, gambar panduan (path img service).
 * `requires_photo` reserved untuk fase eksekusi checklist (fase C).
 */
class SopStep extends Model
{
    use HasFactory;

    protected $connection = 'mysql';
    protected $table = 'sop_steps';
    protected $guarded = [];

    protected $casts = [
        'requires_photo' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function sop(): BelongsTo
    {
        return $this->belongsTo(Sop::class, 'sop_id');
    }
}
