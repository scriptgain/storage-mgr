<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bucket;
use App\Models\StorageObject;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StorageObjectController extends Controller
{
    public function index(Request $request)
    {
        return StorageObject::visibleTo($request->user())
            ->with('bucket:id,name')
            ->when($request->integer('bucket_id'), fn ($q, $id) => $q->where('bucket_id', $id))
            ->latest()
            ->paginate(50);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'bucket_id' => ['required', 'integer', 'exists:buckets,id'],
            'key' => ['required', 'string', 'max:1024'],
            'content_type' => ['nullable', 'string', 'max:190'],
            'size_bytes' => ['nullable', 'integer', 'min:0'],
            'is_folder' => ['sometimes', 'boolean'],
        ]);

        $bucket = Bucket::findOrFail($data['bucket_id']);
        abort_unless($bucket->isVisibleTo($request->user()), 403);

        $isFolder = $request->boolean('is_folder');

        $key = trim($data['key'], '/');
        abort_if($key === '', 422, 'Enter a name.');
        $key = $isFolder ? $key . '/' : $key;

        $object = $bucket->objects()->updateOrCreate(
            ['key' => $key],
            [
                'size_bytes' => $isFolder ? 0 : (int) ($data['size_bytes'] ?? 0),
                'content_type' => $isFolder ? null : ($data['content_type'] ?? null),
                'etag' => $isFolder ? null : Str::lower(Str::random(32)),
                'last_modified' => now(),
            ]
        );
        $bucket->refreshStats();

        return response()->json($object->load('bucket:id,name'), 201);
    }

    public function show(StorageObject $storageObject)
    {
        abort_unless($storageObject->isVisibleTo(auth()->user()), 403);

        return $storageObject->load('bucket:id,name');
    }

    public function destroy(StorageObject $storageObject)
    {
        abort_unless($storageObject->isVisibleTo(auth()->user()), 403);

        $bucket = $storageObject->bucket;
        $storageObject->delete();
        $bucket?->refreshStats();

        return response()->noContent();
    }
}
