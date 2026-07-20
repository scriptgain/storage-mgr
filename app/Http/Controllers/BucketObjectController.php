<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Bucket;
use App\Models\StorageObject;
use App\Services\ObjectStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The object browser inside a bucket (App\Http\Controllers\BucketController::show).
 * Uploads store real bytes through App\Services\ObjectStorage; size, content type
 * and the MD5 etag are all derived from the stored file rather than trusted from
 * the request. "New folder" still writes a zero-byte key with a trailing slash
 * (the S3 convention for folder placeholders), which has no bytes by definition.
 */
class BucketObjectController extends Controller
{
    public function __construct(private readonly ObjectStorage $storage) {}

    public function store(Request $request, Bucket $bucket)
    {
        abort_unless($bucket->isVisibleTo(auth()->user()), 403);
        $isFolder = $request->boolean('is_folder');

        $data = $request->validate([
            'key' => ['required', 'string', 'max:1024'],
            // A real file is required for objects; folders are placeholders only.
            'file' => [$isFolder ? 'prohibited' : 'required', 'file', 'max:'.(int) config('storage.max_upload_kb')],
        ]);

        $key = trim($data['key'], '/');
        abort_if($key === '', 422, 'Enter a name.');
        $key = $isFolder ? $key . '/' : $key;

        $meta = ['size_bytes' => 0, 'content_type' => null, 'etag' => null];
        // A versioned bucket keeps every write as its own version.
        $versionId = $bucket->versioning ? (string) (int) (microtime(true) * 1000).bin2hex(random_bytes(4)) : 'null';

        if (! $isFolder) {
            $file = $request->file('file');
            $existing = $bucket->objects()->where('key', $key)->where('is_latest', true)->first();

            if ($this->storage->wouldExceedQuota($bucket, (int) $file->getSize(), $existing)) {
                return back()->withInput()->with('warning', "Upload would exceed the quota for bucket \"{$bucket->name}\".");
            }

            // Overwriting a key in an unversioned bucket discards the old
            // bytes; a versioned bucket keeps them as the previous version.
            if ($existing && ! $bucket->versioning) {
                $this->storage->delete($existing);
            }

            $meta = $this->storage->put($bucket, $key, $file, $versionId);
        }

        if ($bucket->versioning) {
            $bucket->objects()->where('key', $key)->update(['is_latest' => false]);
        }

        $object = $bucket->objects()->updateOrCreate(
            ['key' => $key, 'version_id' => $versionId],
            $meta + ['last_modified' => now(), 'is_latest' => true, 'is_delete_marker' => false]
        );
        $bucket->refreshStats();
        AuditLog::record(
            'created',
            ($isFolder ? "Folder \"{$object->key}\"" : "Object \"{$object->key}\"") . " added to bucket \"{$bucket->name}\"",
            $object
        );

        return back()->with('status', $isFolder ? "Folder \"{$key}\" created." : "Object \"{$key}\" uploaded.");
    }

    /** Stream an object's bytes back to the browser. */
    public function download(Bucket $bucket, StorageObject $object)
    {
        abort_unless($bucket->isVisibleTo(auth()->user()), 403);
        abort_unless($object->bucket_id === $bucket->id, 404);
        abort_if($object->isFolder(), 404, 'Folders have no contents to download.');

        $path = $this->storage->pathForObject($object);
        // Metadata can outlive its bytes (restores, manual cleanup) — say so
        // plainly rather than serving a zero-byte file as if it were the object.
        abort_unless(is_file($path), 410, 'The stored data for this object is no longer available.');

        return response()->download($path, $object->baseName(), [
            'Content-Type' => $object->content_type ?: 'application/octet-stream',
            'ETag' => $object->etag ? '"'.$object->etag.'"' : null,
        ]);
    }

    public function destroy(Bucket $bucket, StorageObject $object)
    {
        abort_unless($bucket->isVisibleTo(auth()->user()), 403);
        abort_unless($object->bucket_id === $bucket->id, 404);

        $key = $object->key;
        $this->storage->delete($object);
        $object->delete();
        $bucket->refreshStats();
        AuditLog::record('deleted', "Object \"{$key}\" deleted from bucket \"{$bucket->name}\"");

        return back()->with('status', "\"{$key}\" deleted.");
    }

    /**
     * Bulk-delete selected objects from this bucket. Only operates on the ids
     * explicitly submitted that belong to this bucket.
     */
    public function bulkDestroy(Request $request, Bucket $bucket)
    {
        abort_unless($bucket->isVisibleTo(auth()->user()), 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $ids = $bucket->objects()->whereIn('id', $data['ids'])->pluck('id');
        if ($ids->isEmpty()) {
            return back()->with('warning', 'No matching objects were selected.');
        }

        // Remove bytes before the rows, or the paths become unrecoverable.
        $bucket->objects()->whereIn('id', $ids->all())->get()
            ->each(fn (StorageObject $o) => $this->storage->delete($o));

        $count = $bucket->objects()->whereIn('id', $ids->all())->delete();
        $bucket->refreshStats();
        AuditLog::record('deleted', "Bulk deleted {$count} object".($count === 1 ? '' : 's')." from bucket \"{$bucket->name}\"");

        return back()->with('status', $count.' object'.($count === 1 ? '' : 's').' deleted.');
    }
}
