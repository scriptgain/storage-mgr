<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Policy extends Model
{
    protected $fillable = ['user_id', 'name', 'description', 'document'];

    public function accessKeys(): HasMany
    {
        return $this->hasMany(AccessKey::class);
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

    /** Admins see all policies; everyone else sees rows they own or are assigned to. */
    public function scopeVisibleTo($query, ?User $user)
    {
        if ($user && ! $user->isAdmin()) {
            $query->where(function ($q) use ($user) {
                $q->where('policies.user_id', $user->id)
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

    /** A sensible default S3-style policy document for the create form. */
    public static function defaultDocument(): string
    {
        return json_encode([
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Effect' => 'Allow',
                    'Action' => ['s3:GetObject', 's3:PutObject', 's3:ListBucket'],
                    'Resource' => ['arn:aws:s3:::*', 'arn:aws:s3:::*/*'],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
