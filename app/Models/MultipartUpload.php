<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An in-progress multipart upload: a key reserved in a bucket, plus its parts. */
class MultipartUpload extends Model
{
    protected $fillable = [
        'bucket_id', 'user_id', 'object_key', 'upload_id', 'content_type',
    ];

    public function bucket(): BelongsTo
    {
        return $this->belongsTo(Bucket::class);
    }

    public function parts(): HasMany
    {
        return $this->hasMany(MultipartPart::class)->orderBy('part_number');
    }

    public function totalSize(): int
    {
        return (int) $this->parts()->sum('size_bytes');
    }
}
