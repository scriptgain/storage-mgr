<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorageObject extends Model
{
    protected $table = 'storage_objects';

    protected $fillable = [
        'bucket_id', 'key', 'size_bytes', 'content_type', 'etag', 'last_modified',
        'tags', 'version_id', 'is_latest', 'is_delete_marker',
        'lock_mode', 'lock_retain_until', 'legal_hold', 'encrypted',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'is_latest' => 'boolean',
            'is_delete_marker' => 'boolean',
            'legal_hold' => 'boolean',
            'encrypted' => 'boolean',
            'lock_retain_until' => 'datetime',
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
    /**
     * Only the live objects: the current version of each key, excluding keys
     * whose newest version is a delete marker (those read as absent).
     */
    public function scopeCurrent($query)
    {
        return $query->where('is_latest', true)->where('is_delete_marker', false);
    }

    /**
     * Is this version protected from deletion right now? A legal hold blocks
     * removal indefinitely; retention blocks it until the date passes.
     */
    public function isLocked(): bool
    {
        if ($this->legal_hold) {
            return true;
        }

        return $this->lock_retain_until !== null && $this->lock_retain_until->isFuture();
    }

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
