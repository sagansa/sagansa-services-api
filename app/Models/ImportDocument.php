<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Support\ImageUrlResolver;

class ImportDocument extends Model
{
    protected $connection = 'mysql';
    use HasFactory;

    protected $guarded = [];

    protected $appends = ['file_url'];

    public function importPurchase()
    {
        return $this->belongsTo(ImportPurchase::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getFileUrlAttribute(): ?string
    {
        return ImageUrlResolver::resolve($this->file_path);
    }
}
