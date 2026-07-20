<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bucket;
use App\Models\StorageObject;
use App\Services\ObjectStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StorageObjectController extends Controller
{
    public function __construct(private readonly ObjectStorage $storage) {}

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
        $isFolder = $request->boolean('is_folder');

        $data = $request->validate([
            'bucket_id' => ['required', 'integer', 'exists:buckets,id'],
            'key' => ['required', 'string', 'max:1024'],
            'is_folder' => ['sometimes', 'boolean'],
            // Size, content type and etag are derived from the stored file, never
            // taken from the request — a caller cannot claim bytes it didn't send.
            'file' => [$isFolder ? 'prohibited' : 'required', 'file', 'max:'.(int) config('storage.max_upload_kb')],
        ]);

        $bucket = Bucket::findOrFail($data['bucket_id']);
        abort_unless($bucket->isVisibleTo($request->user()), 403);

        $key = trim($data['key'], '/');
        abort_if($key === '', 422, 'Enter a name.');
        $key = $isFolder ? $key . '/' : $key;

        $meta = ['size_bytes' => 0, 'content_type' => null, 'etag' => null];

        if (! $isFolder) {
            $file = $request->file('file');
            $existing = $bucket->objects()->where('key', $key)->first();

            abort_if(
                $this->storage->wouldExceedQuota($bucket, (int) $file->getSize(), $existing),
                422,
                "Upload would exceed the quota for bucket \"{$bucket->name}\"."
            );

            if ($existing) {
                $this->storage->delete($existing);
            }

            $meta = $this->storage->put($bucket, $key, $file);
        }

        $object = $bucket->objects()->updateOrCreate(
            ['key' => $key],
            $meta + ['last_modified' => now()]
        );
        $bucket->refreshStats();

        return response()->json($object->load('bucket:id,name'), 201);
    }

    public function show(StorageObject $storageObject)
    {
        abort_unless($storageObject->isVisibleTo(auth()->user()), 403);

        return $storageObject->load('bucket:id,name');
    }

    /** Download an object's bytes. */
    public function content(StorageObject $storageObject)
    {
        abort_unless($storageObject->isVisibleTo(auth()->user()), 403);
        abort_if($storageObject->isFolder(), 404, 'Folders have no contents to download.');

        $path = $this->storage->pathFor($storageObject->bucket, $storageObject->key);
        abort_unless(is_file($path), 410, 'The stored data for this object is no longer available.');

        return response()->download($path, $storageObject->baseName(), [
            'Content-Type' => $storageObject->content_type ?: 'application/octet-stream',
            'ETag' => $storageObject->etag ? '"'.$storageObject->etag.'"' : null,
        ]);
    }

    public function destroy(StorageObject $storageObject)
    {
        abort_unless($storageObject->isVisibleTo(auth()->user()), 403);

        $bucket = $storageObject->bucket;
        $this->storage->delete($storageObject);
        $storageObject->delete();
        $bucket?->refreshStats();

        return response()->noContent();
    }
}
