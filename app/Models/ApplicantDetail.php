<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicantDetail extends Model
{
    protected $connection = 'mysql_recruitment';

    protected $guarded = ['id'];

    protected $casts = [
        'join_date' => 'date',
        'admin_fee' => 'decimal:2',
    ];

    protected $appends = ['ktp_image_url', 'selfie_image_url'];

    public function getKtpImageUrlAttribute()
    {
        return $this->resolveImageUrl($this->ktp_image);
    }

    public function getSelfieImageUrlAttribute()
    {
        return $this->resolveImageUrl($this->selfie_image);
    }

    /**
     * Relative path -> img.sagansa.id; full URL diteruskan apa adanya.
     */
    private function resolveImageUrl(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        if (str_starts_with($value, 'http')) {
            return $value;
        }

        $value = ltrim($value, '/');
        if (str_starts_with($value, 'storage/')) {
            $value = substr($value, strlen('storage/'));
        }

        $imgBaseUrl = rtrim(env('IMG_SERVICE_URL', 'https://img.sagansa.id'), '/');

        return "{$imgBaseUrl}/storage/{$value}";
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
