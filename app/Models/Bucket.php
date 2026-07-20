<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Bucket extends Model
{
    public const ACCESS_LEVELS = [
        'private' => 'Private',
        'public' => 'Public',
    ];

    protected static function booted(): void
    {
        // Reclaim the bucket's bytes whenever it is deleted, so disk usage can
        // never outlive the record. Note this fires for model deletes only —
        // mass deletes on the query builder must call ObjectStorage directly.
        static::deleting(function (Bucket $bucket) {
            app(\App\Services\ObjectStorage::class)->deleteBucket($bucket);
        });
    }

    protected $fillable = [
        'user_id', 'name', 'region', 'access', 'versioning', 'quota_bytes', 'object_count', 'size_bytes',
        'tags',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'versioning' => 'boolean',
            'quota_bytes' => 'integer',
            'object_count' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    public function objects(): HasMany
    {
        return $this->hasMany(StorageObject::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Additional users (beyond the owner) who may see this resource. */
    public function assignees(): MorphToMany
    {
        return $this->morphToMany(User::class, 'assignable', 'assignments');
    }

    /** Replace the assignee set with the given user ids. */
    public function syncAssignees(array $userIds): void
    {
        $this->assignees()->sync($userIds);
    }

    /** Admins see all buckets; everyone else sees rows they own or are assigned to. */
    public function scopeVisibleTo($query, ?User $user)
    {
        if ($user && ! $user->isAdmin()) {
            $query->where(function ($q) use ($user) {
                $q->where('buckets.user_id', $user->id)
                    ->orWhereHas('assignees', fn ($a) => $a->whereKey($user->id));
            });
        }

        return $query;
    }

    public function isVisibleTo(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->isAdmin() || $this->user_id === $user->id) {
            return true;
        }

        return $this->assignees()->whereKey($user->id)->exists();
    }

    public function isPublic(): bool
    {
        return $this->access === 'public';
    }

    /** Percent of quota used, or null when the bucket has no quota. */
    public function quotaUsedPercent(): ?float
    {
        if (! $this->quota_bytes) {
            return null;
        }

        return round(min(100, ($this->size_bytes / $this->quota_bytes) * 100), 1);
    }

    /** Recompute object_count/size_bytes from the objects table. Call after any object mutation. */
    public function refreshStats(): void
    {
        // Only live objects count: old versions and delete markers are not
        // what a user means by "what is in this bucket".
        $this->object_count = $this->objects()->current()->count();
        $this->size_bytes = (int) $this->objects()->current()->sum('size_bytes');
        $this->saveQuietly();
    }
}
