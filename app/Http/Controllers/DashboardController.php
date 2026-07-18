<?php

namespace App\Http\Controllers;

use App\Models\AccessKey;
use App\Models\Bucket;
use App\Support\Bytes;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $user = auth()->user();

        $buckets = Bucket::visibleTo($user)->count();
        $publicBuckets = Bucket::visibleTo($user)->where('access', 'public')->count();
        $objects = (int) Bucket::visibleTo($user)->sum('object_count');
        $usedBytes = (int) Bucket::visibleTo($user)->sum('size_bytes');
        $activeKeys = AccessKey::visibleTo($user)->where('status', 'active')->count();
        $totalKeys = AccessKey::visibleTo($user)->count();

        $stats = [
            'buckets' => $buckets,
            'objects' => $objects,
            'storage_used' => Bytes::human($usedBytes),
            'active_keys' => $activeKeys,
        ];

        // Provisioned-capacity gauge. Only buckets with an explicit quota count
        // toward the denominator; "unlimited" buckets are surfaced as context.
        $provisioned = (int) Bucket::visibleTo($user)->whereNotNull('quota_bytes')->sum('quota_bytes');
        $usedInQuota = (int) Bucket::visibleTo($user)->whereNotNull('quota_bytes')->sum('size_bytes');
        $unlimited = Bucket::visibleTo($user)->whereNull('quota_bytes')->count();

        $capacity = [
            'used' => $usedInQuota,
            'total' => $provisioned,
            'unlimited' => $unlimited,
        ];

        // Signature visual: storage distribution across the largest buckets.
        $byBucket = Bucket::visibleTo($user)
            ->orderByDesc('size_bytes')
            ->limit(8)
            ->get(['id', 'name', 'region', 'access', 'object_count', 'size_bytes']);

        $recent = Bucket::visibleTo($user)->latest()->limit(6)->get();

        return view('dashboard', compact(
            'stats', 'recent', 'byBucket', 'capacity',
            'publicBuckets', 'usedBytes', 'activeKeys', 'totalKeys',
        ));
    }
}
