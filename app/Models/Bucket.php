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

    protected $fillable = [
        'user_id', 'name', 'region', 'access', 'versioning', 'quota_bytes', 'object_count', 'size_bytes',
    ];

    protected function casts(): array
    {
        return [
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
        $this->object_count = $this->objects()->count();
        $this->size_bytes = (int) $this->objects()->sum('size_bytes');
        $this->saveQuietly();
    }
}
