<?php

namespace GregPriday\WorkManager\Models;

use Illuminate\Database\Eloquent\Model;

class WorkIdempotencyKey extends Model
{
    const UPDATED_AT = null; // Only created_at timestamp

    protected $fillable = [
        'scope',
        'key_hash',
        'response_snapshot',
    ];

    protected $casts = [
        'response_snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Scope to find by scope and hash.
     */
    public function scopeForKey($query, string $scope, string $keyHash)
    {
        return $query->where('scope', $scope)
            ->where('key_hash', $keyHash);
    }
}
