<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorageObject extends Model
{
    protected $table = 'storage_objects';

    protected $fillable = [
        'bucket_id', 'key', 'size_bytes', 'content_type', 'etag', 'last_modified',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'last_modified' => 'datetime',
        ];
    }

    public function bucket(): BelongsTo
    {
        return $this->belongsTo(Bucket::class);
    }

    /** Visibility follows the parent bucket's owner. */
    public function scopeVisibleTo($query, ?User $user)
    {
        if ($user && ! $user->isAdmin()) {
            $query->whereHas('bucket', fn ($b) => $b->where('user_id', $user->id));
        }

        return $query;
    }

    public function isVisibleTo(?User $user): bool
    {
        return $user && ($user->isAdmin() || ($this->bucket && $this->bucket->user_id === $user->id));
    }

    /** Is this a "folder" placeholder (key ends with a trailing slash)? */
    public function isFolder(): bool
    {
        return str_ends_with($this->key, '/');
    }

    /** The last path segment, for display. */
    public function baseName(): string
    {
        return basename(rtrim($this->key, '/'));
    }
}
