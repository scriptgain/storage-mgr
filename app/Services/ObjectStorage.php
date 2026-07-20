<?php

namespace App\Services;

use App\Models\Bucket;
use App\Models\StorageObject;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

/**
 * The data plane. Everything else in this app models S3 concepts (buckets,
 * policies, access keys); this is the piece that actually moves bytes.
 *
 *  - Layout: <root>/<bucket id>/<sharded hash of the key>. Keyed by bucket id
 *    (not name) so renaming a bucket never moves data, and hashed so arbitrary
 *    object keys can never escape the bucket directory via "../" or collide
 *    with the filesystem's path length and character limits.
 *  - The etag is a real MD5 of the stored bytes, matching S3's semantics for
 *    single-part uploads, so it can be used to detect corruption.
 *  - Fail-soft on read/delete: a missing file never takes a page down, since
 *    metadata rows can outlive their bytes (restores, manual cleanup).
 */
class ObjectStorage
{
    public function root(): string
    {
        return rtrim((string) config('storage.root'), '/');
    }

    /** Absolute directory holding one bucket's objects. */
    public function bucketDir(Bucket $bucket): string
    {
        return $this->root().'/'.$bucket->id;
    }

    /**
     * Absolute path for an object key. The key is hashed rather than used as a
     * path so that "../../etc/passwd", deep nesting, and exotic characters are
     * all inert; the human-readable key stays in the database.
     */
    public function pathFor(Bucket $bucket, string $key): string
    {
        $hash = hash('sha256', $key);

        return $this->bucketDir($bucket).'/'.substr($hash, 0, 2).'/'.substr($hash, 2, 2).'/'.$hash;
    }

    public function exists(StorageObject $object): bool
    {
        return ! $object->isFolder() && is_file($this->pathFor($object->bucket, $object->key));
    }

    /** Bytes currently on disk for an object, or null when the file is gone. */
    public function sizeOnDisk(StorageObject $object): ?int
    {
        $path = $this->pathFor($object->bucket, $object->key);
        if (! is_file($path)) {
            return null;
        }
        $size = @filesize($path);

        return $size === false ? null : (int) $size;
    }

    /**
     * Store an uploaded file and return the metadata the caller should persist.
     *
     * @return array{size_bytes:int, content_type:string, etag:string}
     */
    public function put(Bucket $bucket, string $key, UploadedFile $file): array
    {
        $path = $this->pathFor($bucket, $key);
        $dir = dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new FileException("Could not create storage directory: {$dir}");
        }

        // Hash before moving: the temp file is readable now and the destination
        // may live on a different filesystem.
        $etag = md5_file($file->getRealPath());
        $size = (int) $file->getSize();
        $type = $file->getClientMimeType() ?: 'application/octet-stream';

        $file->move($dir, basename($path));

        if ($etag === false) {
            $etag = md5_file($path) ?: '';
        }

        return [
            'size_bytes' => $size,
            'content_type' => $type,
            'etag' => $etag,
        ];
    }

    /** Directory holding the parts of an in-progress multipart upload. */
    public function multipartDir(string $uploadId): string
    {
        // Keep parts outside the bucket directories so a half-finished upload
        // can never be mistaken for a stored object.
        return $this->root().'/_multipart/'.preg_replace('/[^A-Za-z0-9]/', '', $uploadId);
    }

    public function partPath(string $uploadId, int $partNumber): string
    {
        return $this->multipartDir($uploadId).'/'.$partNumber;
    }

    /** Drop every part file for an upload (completion or abort). */
    public function discardMultipart(string $uploadId): void
    {
        $dir = $this->multipartDir($uploadId);
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    /** Ensure a directory exists, returning false if it cannot be created. */
    public function ensureDir(string $dir): bool
    {
        return is_dir($dir) || @mkdir($dir, 0775, true) || is_dir($dir);
    }

    /** Remove one object's bytes. Safe to call when the file is already gone. */
    public function delete(StorageObject $object): void
    {
        if ($object->isFolder()) {
            return;
        }

        $path = $this->pathFor($object->bucket, $object->key);
        if (is_file($path)) {
            @unlink($path);
            $this->pruneEmptyDirs(dirname($path), $this->bucketDir($object->bucket));
        }
    }

    /** Remove every byte belonging to a bucket (used when the bucket is deleted). */
    public function deleteBucket(Bucket $bucket): void
    {
        $dir = $this->bucketDir($bucket);
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * Would storing $incoming bytes push the bucket past its quota? Replacing an
     * existing key only counts the difference, matching how S3 overwrites.
     */
    public function wouldExceedQuota(Bucket $bucket, int $incoming, ?StorageObject $replacing = null): bool
    {
        $quota = (int) ($bucket->quota_bytes ?? 0);
        if ($quota <= 0) {
            return false;
        }

        $current = (int) $bucket->objects()->sum('size_bytes');
        $freed = (int) ($replacing?->size_bytes ?? 0);

        return ($current - $freed + $incoming) > $quota;
    }

    /** Walk up removing now-empty shard directories, never past the bucket root. */
    private function pruneEmptyDirs(string $dir, string $stopAt): void
    {
        $stopAt = rtrim($stopAt, '/');
        while (str_starts_with($dir, $stopAt) && $dir !== $stopAt) {
            if (! is_dir($dir) || (new \FilesystemIterator($dir))->valid()) {
                return;
            }
            @rmdir($dir);
            $dir = dirname($dir);
        }
    }
}
