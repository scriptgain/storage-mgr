<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;

class AccessKey extends Model
{
    public const STATUSES = [
        'active' => 'Active',
        'disabled' => 'Disabled',
    ];

    protected $fillable = [
        'user_id', 'name', 'access_key_id', 'secret_key', 'status', 'policy_id', 'last_used_at',
    ];

    protected $hidden = ['secret_key'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
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

    /** Admins see all keys; everyone else sees rows they own or are assigned to. */
    public function scopeVisibleTo($query, ?User $user)
    {
        if ($user && ! $user->isAdmin()) {
            $query->where(function ($q) use ($user) {
                $q->where('access_keys.user_id', $user->id)
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

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    // No ambiguous characters (0/O, 1/I/L) for human-readable secrets.
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** A unique MinIO/S3-style access key id: AKIA + 16 upper-alnum. */
    public static function generateAccessKeyId(): string
    {
        do {
            $id = 'AKIA' . self::block(16);
        } while (static::where('access_key_id', $id)->exists());

        return $id;
    }

    /** A 40-char secret key. Only ever returned in plaintext at creation time. */
    public static function generateSecretKey(): string
    {
        return Str::random(40);
    }

    private static function block(int $len): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $len; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }
}
