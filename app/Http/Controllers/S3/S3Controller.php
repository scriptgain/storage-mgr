<?php

namespace App\Http\Controllers\S3;

use App\Http\Controllers\Controller;
use App\Models\Bucket;
use App\Models\StorageObject;
use App\Models\User;
use App\Services\ObjectStorage;
use App\Services\S3\ChunkedDecoder;
use App\Services\S3\S3Xml;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The S3 wire protocol: what makes aws-cli, boto3 and any S3 SDK able to talk to
 * this server. It maps S3 operations onto the same Bucket/StorageObject models
 * and ObjectStorage backend the web console uses, so both stay consistent.
 *
 * We serve path-style addressing (/{bucket}/{key}). Virtual-host style
 * (bucket.host/key) would need wildcard DNS and a wildcard certificate, so
 * clients should point --endpoint-url here and use path addressing.
 */
class S3Controller extends Controller
{
    public function __construct(private readonly ObjectStorage $storage) {}

    private function user(Request $request): ?User
    {
        return $request->attributes->get('s3_user');
    }

    // ---------------------------------------------------------------- service

    /** GET / — ListBuckets */
    public function listBuckets(Request $request)
    {
        $user = $this->user($request);
        $buckets = Bucket::visibleTo($user)->orderBy('name')->get();

        $items = '';
        foreach ($buckets as $b) {
            $items .= '<Bucket><Name>'.S3Xml::esc($b->name).'</Name>'
                .'<CreationDate>'.$b->created_at->toIso8601ZuluString().'</CreationDate></Bucket>';
        }

        return S3Xml::response(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<ListAllMyBucketsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            .'<Owner><ID>'.(int) ($user?->id ?? 0).'</ID><DisplayName>'.S3Xml::esc((string) $user?->name).'</DisplayName></Owner>'
            .'<Buckets>'.$items.'</Buckets></ListAllMyBucketsResult>'
        );
    }

    // ----------------------------------------------------------------- bucket

    /** PUT /{bucket} — CreateBucket */
    public function createBucket(Request $request, string $bucket)
    {
        if (! preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket)) {
            return S3Xml::error('InvalidBucketName', null, '/'.$bucket);
        }

        $existing = Bucket::where('name', $bucket)->first();
        if ($existing) {
            return $existing->isVisibleTo($this->user($request))
                ? S3Xml::error('BucketAlreadyOwnedByYou', null, '/'.$bucket)
                : S3Xml::error('BucketAlreadyExists', null, '/'.$bucket);
        }

        Bucket::create([
            'user_id' => $this->user($request)?->id,
            'name' => $bucket,
            'region' => 'us-east-1',
            'access' => 'private',
            'versioning' => false,
        ]);

        // No Location header: PHP turns any response carrying one into a 302
        // unless the status is forced, and S3 clients treat a redirect on
        // CreateBucket as a failure. The 200 is what they actually check.
        return response('', 200);
    }

    /** HEAD /{bucket} — HeadBucket */
    public function headBucket(Request $request, string $bucket)
    {
        $b = $this->findBucket($request, $bucket);

        return $b instanceof Bucket ? response('', 200) : response('', $b->getStatusCode());
    }

    /** DELETE /{bucket} — DeleteBucket. S3 refuses when the bucket still holds keys. */
    public function deleteBucket(Request $request, string $bucket)
    {
        $b = $this->findBucket($request, $bucket);
        if (! $b instanceof Bucket) {
            return $b;
        }

        if ($b->objects()->exists()) {
            return S3Xml::error('BucketNotEmpty', null, '/'.$bucket);
        }

        $b->delete(); // the model's deleting hook reclaims the directory

        return response('', 204);
    }

    /** GET /{bucket} — ListObjectsV2 (and the V1 shape, which shares these fields) */
    public function listObjects(Request $request, string $bucket)
    {
        $b = $this->findBucket($request, $bucket);
        if (! $b instanceof Bucket) {
            return $b;
        }

        $prefix = (string) $request->query('prefix', '');
        $delimiter = (string) $request->query('delimiter', '');
        $maxKeys = max(1, min(1000, (int) $request->query('max-keys', 1000)));
        $isV2 = (string) $request->query('list-type') === '2';
        $after = (string) ($isV2 ? $request->query('start-after', '') : $request->query('marker', ''));
        $token = (string) $request->query('continuation-token', '');
        if ($token !== '') {
            $after = (string) base64_decode($token, true);
        }

        $query = $b->objects()->orderBy('key');
        if ($prefix !== '') {
            $query->where('key', 'like', str_replace(['%', '_'], ['\%', '\_'], $prefix).'%');
        }
        if ($after !== '') {
            $query->where('key', '>', $after);
        }

        // Fetch one extra row to detect truncation without a second count query.
        $rows = $query->limit($maxKeys + 1)->get();
        $truncated = $rows->count() > $maxKeys;
        $rows = $rows->take($maxKeys);

        $contents = '';
        $prefixes = [];

        foreach ($rows as $o) {
            // With a delimiter, keys sharing a path segment collapse into a
            // CommonPrefix so clients can render folders.
            if ($delimiter !== '') {
                $rest = $prefix !== '' ? substr($o->key, strlen($prefix)) : $o->key;
                $pos = strpos($rest, $delimiter);
                if ($pos !== false) {
                    $prefixes[$prefix.substr($rest, 0, $pos + strlen($delimiter))] = true;
                    continue;
                }
            }

            $contents .= '<Contents>'
                .'<Key>'.S3Xml::esc($o->key).'</Key>'
                .'<LastModified>'.($o->last_modified ?? $o->updated_at)->toIso8601ZuluString().'</LastModified>'
                .'<ETag>&quot;'.S3Xml::esc((string) $o->etag).'&quot;</ETag>'
                .'<Size>'.(int) $o->size_bytes.'</Size>'
                .'<StorageClass>STANDARD</StorageClass>'
                .'</Contents>';
        }

        $common = '';
        foreach (array_keys($prefixes) as $p) {
            $common .= '<CommonPrefixes><Prefix>'.S3Xml::esc($p).'</Prefix></CommonPrefixes>';
        }

        $last = $rows->last();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            .'<Name>'.S3Xml::esc($b->name).'</Name>'
            .'<Prefix>'.S3Xml::esc($prefix).'</Prefix>'
            .'<MaxKeys>'.$maxKeys.'</MaxKeys>'
            .'<Delimiter>'.S3Xml::esc($delimiter).'</Delimiter>'
            .'<IsTruncated>'.($truncated ? 'true' : 'false').'</IsTruncated>'
            .($isV2 ? '<KeyCount>'.$rows->count().'</KeyCount>' : '')
            .($truncated && $last ? '<NextContinuationToken>'.base64_encode($last->key).'</NextContinuationToken>' : '')
            .$contents.$common
            .'</ListBucketResult>';

        return S3Xml::response($xml);
    }

    // ----------------------------------------------------------------- object

    /** PUT /{bucket}/{key} — PutObject */
    public function putObject(Request $request, string $bucket, string $key)
    {
        $b = $this->findBucket($request, $bucket);
        if (! $b instanceof Bucket) {
            return $b;
        }

        $key = ltrim($key, '/');
        if ($key === '') {
            return S3Xml::error('NoSuchKey', null, '/'.$bucket);
        }

        // Copy the body to a temp file first: it may be chunk-framed, and we
        // need the real length and MD5 before deciding whether it fits the quota.
        $tmp = tempnam(sys_get_temp_dir(), 's3put');
        $out = fopen($tmp, 'w+b');
        $in = fopen('php://input', 'rb');

        if (ChunkedDecoder::isChunked($request)) {
            ChunkedDecoder::decodeStream($in, $out);
        } else {
            stream_copy_to_stream($in, $out);
        }

        fclose($in);
        fflush($out);
        $size = (int) ftell($out);
        fclose($out);

        $etag = md5_file($tmp) ?: '';
        $existing = $b->objects()->where('key', $key)->first();

        if ($this->storage->wouldExceedQuota($b, $size, $existing)) {
            @unlink($tmp);

            return S3Xml::error('QuotaExceeded', "The bucket \"{$b->name}\" quota would be exceeded.", '/'.$bucket.'/'.$key);
        }

        if ($existing) {
            $this->storage->delete($existing);
        }

        $path = $this->storage->pathFor($b, $key);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        rename($tmp, $path);
        @chmod($path, 0664);

        $b->objects()->updateOrCreate(['key' => $key], [
            'size_bytes' => $size,
            'content_type' => $request->header('Content-Type') ?: 'application/octet-stream',
            'etag' => $etag,
            'last_modified' => now(),
        ]);
        $b->refreshStats();

        return response('', 200)->header('ETag', '"'.$etag.'"');
    }

    /** GET /{bucket}/{key} — GetObject, with Range support */
    public function getObject(Request $request, string $bucket, string $key)
    {
        [$b, $o, $err] = $this->findObject($request, $bucket, $key);
        if ($err) {
            return $err;
        }

        $path = $this->storage->pathFor($b, $o->key);
        if (! is_file($path)) {
            return S3Xml::error('NoSuchKey', 'The stored data for this object is no longer available.', '/'.$bucket.'/'.$key);
        }

        $size = (int) filesize($path);
        $headers = [
            'Content-Type' => $o->content_type ?: 'application/octet-stream',
            'ETag' => '"'.$o->etag.'"',
            'Last-Modified' => ($o->last_modified ?? $o->updated_at)->toRfc7231String(),
            'Accept-Ranges' => 'bytes',
        ];

        // Range requests matter for large objects: without them clients cannot
        // resume, and tools that probe with a byte range will fail outright.
        $range = (string) $request->header('Range');
        $start = 0;
        $end = $size - 1;
        $partial = false;

        if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
            $partial = true;
            if ($m[1] === '') {
                $start = max(0, $size - (int) $m[2]);
            } else {
                $start = (int) $m[1];
                if ($m[2] !== '') {
                    $end = min((int) $m[2], $size - 1);
                }
            }
            if ($start > $end || $start >= $size) {
                return response('', 416)->header('Content-Range', 'bytes */'.$size);
            }
            $headers['Content-Range'] = 'bytes '.$start.'-'.$end.'/'.$size;
        }

        $length = $end - $start + 1;
        $headers['Content-Length'] = (string) $length;

        return new StreamedResponse(function () use ($path, $start, $length) {
            $fh = fopen($path, 'rb');
            fseek($fh, $start);
            $remaining = $length;
            while ($remaining > 0 && ! feof($fh)) {
                $buf = fread($fh, (int) min(8192, $remaining));
                if ($buf === false || $buf === '') {
                    break;
                }
                echo $buf;
                flush();
                $remaining -= strlen($buf);
            }
            fclose($fh);
        }, $partial ? 206 : 200, $headers);
    }

    /** HEAD /{bucket}/{key} — HeadObject */
    public function headObject(Request $request, string $bucket, string $key)
    {
        [$b, $o, $err] = $this->findObject($request, $bucket, $key);
        if ($err) {
            return response('', $err->getStatusCode());
        }

        return response('', 200)->withHeaders([
            'Content-Type' => $o->content_type ?: 'application/octet-stream',
            'Content-Length' => (string) (int) $o->size_bytes,
            'ETag' => '"'.$o->etag.'"',
            'Last-Modified' => ($o->last_modified ?? $o->updated_at)->toRfc7231String(),
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /** DELETE /{bucket}/{key} — DeleteObject. S3 returns 204 even if absent. */
    public function deleteObject(Request $request, string $bucket, string $key)
    {
        $b = $this->findBucket($request, $bucket);
        if (! $b instanceof Bucket) {
            return $b;
        }

        $o = $b->objects()->where('key', ltrim($key, '/'))->first();
        if ($o) {
            $this->storage->delete($o);
            $o->delete();
            $b->refreshStats();
        }

        return response('', 204);
    }

    // ---------------------------------------------------------------- helpers

    private function findBucket(Request $request, string $name)
    {
        $b = Bucket::where('name', $name)->first();
        if (! $b) {
            return S3Xml::error('NoSuchBucket', null, '/'.$name);
        }
        if (! $b->isVisibleTo($this->user($request))) {
            return S3Xml::error('AccessDenied', null, '/'.$name);
        }

        return $b;
    }

    /** @return array{0:?Bucket,1:?StorageObject,2:mixed} */
    private function findObject(Request $request, string $bucket, string $key): array
    {
        $b = $this->findBucket($request, $bucket);
        if (! $b instanceof Bucket) {
            return [null, null, $b];
        }

        $o = $b->objects()->where('key', ltrim($key, '/'))->first();
        if (! $o) {
            return [$b, null, S3Xml::error('NoSuchKey', null, '/'.$bucket.'/'.$key)];
        }

        return [$b, $o, null];
    }
}
