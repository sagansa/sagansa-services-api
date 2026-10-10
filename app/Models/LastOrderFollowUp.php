<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan follow-up "sudah dihubungi" pada daftar order terakhir user
 * (fitur admin). Satu row = satu tindakan menghubungi; riwayat dipertahankan.
 */
class LastOrderFollowUp extends Model
{
    protected $connection = 'mysql';

    protected $table = 'last_order_follow_ups';

    protected $fillable = ['user_id', 'followed_up_by'];

    public function followedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'followed_up_by');
    }
}
