<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One uploaded part of a multipart upload. */
class MultipartPart extends Model
{
    protected $fillable = [
        'multipart_upload_id', 'part_number', 'size_bytes', 'etag',
    ];

    protected function casts(): array
    {
        return [
            'part_number' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(MultipartUpload::class, 'multipart_upload_id');
    }
}
