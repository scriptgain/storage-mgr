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
        $stats = [
            'buckets' => Bucket::visibleTo($user)->count(),
            'objects' => (int) Bucket::visibleTo($user)->sum('object_count'),
            'storage_used' => Bytes::human(Bucket::visibleTo($user)->sum('size_bytes')),
            'active_keys' => AccessKey::visibleTo($user)->where('status', 'active')->count(),
        ];

        $recent = Bucket::visibleTo($user)->latest()->limit(6)->get();

        return view('dashboard', compact('stats', 'recent'));
    }
}
