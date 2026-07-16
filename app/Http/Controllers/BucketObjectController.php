<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Bucket;
use App\Models\StorageObject;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The object browser inside a bucket (App\Http\Controllers\BucketController::show).
 * This console manages object METADATA — it doesn't proxy real bytes to a storage
 * backend, so "upload" records a key/size/content-type and "new folder" writes a
 * zero-byte key with a trailing slash (S3 convention for folder placeholders).
 */
class BucketObjectController extends Controller
{
    public function store(Request $request, Bucket $bucket)
    {
        abort_unless($bucket->isVisibleTo(auth()->user()), 403);
        $isFolder = $request->boolean('is_folder');

        $data = $request->validate([
            'key' => ['required', 'string', 'max:1024'],
            'content_type' => ['nullable', 'string', 'max:190'],
            'size_bytes' => ['nullable', 'integer', 'min:0'],
        ]);

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
        AuditLog::record(
            'created',
            ($isFolder ? "Folder \"{$object->key}\"" : "Object \"{$object->key}\"") . " added to bucket \"{$bucket->name}\"",
            $object
        );

        return back()->with('status', $isFolder ? "Folder \"{$key}\" created." : "Object \"{$key}\" uploaded.");
    }

    public function destroy(Bucket $bucket, StorageObject $object)
    {
        abort_unless($bucket->isVisibleTo(auth()->user()), 403);
        abort_unless($object->bucket_id === $bucket->id, 404);

        $key = $object->key;
        $object->delete();
        $bucket->refreshStats();
        AuditLog::record('deleted', "Object \"{$key}\" deleted from bucket \"{$bucket->name}\"");

        return back()->with('status', "\"{$key}\" deleted.");
    }
}
