<?php

namespace App\Http\Controllers\S3;

use App\Http\Controllers\Controller;
use App\Models\Bucket;
use App\Models\MultipartPart;
use App\Models\MultipartUpload;
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
 * Both addressing styles are served: path style (/{bucket}/{key}) and
 * virtual-host style (bucket.s3.example.com/key, the SDK default), the latter
 * via a domain-parameter route group so the URI and Host stay exactly as the
 * client signed them.
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

        // PutObjectLockConfiguration shares the bucket PUT route.
        if ($request->has('object-lock')) {
            $b = $this->findBucket($request, $bucket);
            if (! $b instanceof Bucket) {
                return $b;
            }
            $body = $request->getContent();
            preg_match('/<Mode>(GOVERNANCE|COMPLIANCE)<\/Mode>/i', $body, $mode);
            preg_match('/<Days>(\d+)<\/Days>/i', $body, $days);
            preg_match('/<Years>(\d+)<\/Years>/i', $body, $years);

            $b->forceFill([
                'object_lock_enabled' => str_contains($body, '<ObjectLockEnabled>Enabled</ObjectLockEnabled>'),
                'default_lock_mode' => $mode[1] ?? null,
                'default_lock_days' => isset($years[1]) ? ((int) $years[1]) * 365 : (isset($days[1]) ? (int) $days[1] : null),
                // Object Lock requires versioning; enabling one enables the other.
                'versioning' => true,
            ])->save();

            return response('', 200);
        }

        // PutBucketVersioning shares the bucket PUT route.
        if ($request->has('versioning')) {
            $b = $this->findBucket($request, $bucket);
            if (! $b instanceof Bucket) {
                return $b;
            }
            $enabled = str_contains($request->getContent(), '<Status>Enabled</Status>');
            $b->forceFill(['versioning' => $enabled])->save();

            return response('', 200);
        }

        // PutBucketTagging shares the bucket PUT route.
        if ($request->has('tagging')) {
            $b = $this->findBucket($request, $bucket);
            if (! $b instanceof Bucket) {
                return $b;
            }
            $b->forceFill(['tags' => $this->parseTagging($request->getContent())])->save();

            return response('', 204);
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

        // DeleteBucketTagging clears tags rather than removing the bucket.
        if ($request->has('tagging')) {
            $b->forceFill(['tags' => null])->save();

            return response('', 204);
        }

        if ($b->objects()->exists()) {
            return S3Xml::error('BucketNotEmpty', null, '/'.$bucket);
        }

        $b->delete(); // the model's deleting hook reclaims the directory

        return response('', 204);
    }

    /**
     * POST /{bucket}?delete — DeleteObjects.
     *
     * The bulk delete every client uses for recursive removal ("mc rm -r",
     * "aws s3 rm --recursive"). Without it those commands fail, and the bucket
     * can never be emptied enough to drop.
     */
    public function deleteObjects(Request $request, string $bucket)
    {
        $b = $this->findBucket($request, $bucket);
        if (! $b instanceof Bucket) {
            return $b;
        }

        if (! $request->has('delete')) {
            return S3Xml::error('NotImplemented', null, '/'.$bucket);
        }

        preg_match_all('/<Key>(.*?)<\/Key>/s', $request->getContent(), $m);
        $keys = array_map(fn ($k) => html_entity_decode($k, ENT_XML1 | ENT_QUOTES, 'UTF-8'), $m[1] ?? []);
        $quiet = str_contains($request->getContent(), '<Quiet>true</Quiet>');

        $deleted = '';
        $errors = '';
        foreach ($keys as $key) {
            $o = $b->objects()->where('key', $key)->first();
            if ($o && $o->isLocked()) {
                $errors .= '<Error><Key>'.S3Xml::esc($key).'</Key><Code>AccessDenied</Code>'
                    .'<Message>Object is protected by an object lock.</Message></Error>';
                continue;
            }
            if ($o) {
                $this->storage->delete($o);
                $o->delete();
            }
            // S3 reports success even for keys that were already absent.
            if (! $quiet) {
                $deleted .= '<Deleted><Key>'.S3Xml::esc($key).'</Key></Deleted>';
            }
        }
        $b->refreshStats();

        return S3Xml::response(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<DeleteResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'.$deleted.$errors.'</DeleteResult>'
        );
    }

    /** GET /{bucket} — ListObjectsV2 (and the V1 shape, which shares these fields) */
    public function listObjects(Request $request, string $bucket)
    {
        $b = $this->findBucket($request, $bucket);
        if (! $b instanceof Bucket) {
            return $b;
        }

        // Sub-resources that share the bucket GET route. Clients call these
        // during setup — mc asks for the location before it will do anything.
        if ($request->has('location')) {
            return S3Xml::response(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<LocationConstraint xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
                .S3Xml::esc($b->region ?: 'us-east-1')
                .'</LocationConstraint>'
            );
        }

        if ($request->has('uploads')) {
            return $this->listMultipartUploads($b);
        }

        if ($request->has('tagging')) {
            return S3Xml::response($this->taggingXml($b->tags));
        }

        if ($request->has('versioning')) {
            return S3Xml::response(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
                .($b->versioning ? '<Status>Enabled</Status>' : '')
                .'</VersioningConfiguration>'
            );
        }

        if ($request->has('versions')) {
            return $this->listObjectVersions($request, $b);
        }

        if ($request->has('object-lock')) {
            if (! $b->object_lock_enabled) {
                return S3Xml::error('ObjectLockConfigurationNotFoundError', 'Object Lock is not enabled for this bucket.', '/'.$b->name);
            }
            $rule = $b->default_lock_mode
                ? '<Rule><DefaultRetention><Mode>'.S3Xml::esc($b->default_lock_mode).'</Mode>'
                    .'<Days>'.(int) $b->default_lock_days.'</Days></DefaultRetention></Rule>'
                : '';

            return S3Xml::response(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<ObjectLockConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
                .'<ObjectLockEnabled>Enabled</ObjectLockEnabled>'.$rule
                .'</ObjectLockConfiguration>'
            );
        }

        // Unsupported sub-resources must answer in S3's shape, not with the
        // object listing, or clients misread the response entirely.
        foreach (['policy', 'acl', 'lifecycle', 'replication', 'encryption', 'notification', 'cors', 'accelerate', 'logging', 'website', 'requestPayment', 'analytics', 'inventory', 'metrics'] as $sub) {
            if ($request->has($sub)) {
                return $sub === 'versioning'
                    ? S3Xml::response('<?xml version="1.0" encoding="UTF-8"?><VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>')
                    : S3Xml::error('NotImplemented', "The {$sub} sub-resource is not supported.", '/'.$b->name);
            }
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

        $query = $b->objects()->current()->orderBy('key');
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

    /** PUT /{bucket}/{key} — PutObject, or UploadPart when part params are present */
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

        // SDKs address parts on the same PUT route, distinguished by query args.
        if ($request->query('uploadId') && $request->query('partNumber')) {
            return $this->uploadPart($request, $b, $key);
        }

        // PutObjectRetention / PutObjectLegalHold share this route.
        if ($request->has('retention') || $request->has('legal-hold')) {
            $versionId = (string) $request->query('versionId', '');
            $o = $versionId !== ''
                ? $b->objects()->where('key', $key)->where('version_id', $versionId)->first()
                : $b->objects()->where('key', $key)->where('is_latest', true)->first();
            if (! $o) {
                return S3Xml::error('NoSuchKey', null, '/'.$bucket.'/'.$key);
            }

            $body = $request->getContent();

            if ($request->has('legal-hold')) {
                $o->forceFill(['legal_hold' => str_contains($body, '<Status>ON</Status>')])->save();

                return response('', 200);
            }

            preg_match('/<Mode>(GOVERNANCE|COMPLIANCE)<\/Mode>/i', $body, $mode);
            preg_match('/<RetainUntilDate>([^<]+)<\/RetainUntilDate>/i', $body, $until);

            // Retention may be extended but never shortened, and COMPLIANCE
            // cannot be reduced or removed by anyone. That is the whole point
            // of the mode, so it is enforced here rather than trusted.
            if ($o->lock_mode === 'COMPLIANCE' && $o->lock_retain_until && isset($until[1])
                && strtotime($until[1]) < $o->lock_retain_until->timestamp) {
                return S3Xml::error('AccessDenied', 'A COMPLIANCE retention period cannot be shortened.', '/'.$bucket.'/'.$key);
            }

            $o->forceFill([
                'lock_mode' => $mode[1] ?? $o->lock_mode,
                'lock_retain_until' => isset($until[1]) ? date('Y-m-d H:i:s', strtotime($until[1])) : $o->lock_retain_until,
            ])->save();

            return response('', 200);
        }

        // PutObjectTagging shares this route.
        if ($request->has('tagging')) {
            $o = $b->objects()->where('key', $key)->first();
            if (! $o) {
                return S3Xml::error('NoSuchKey', null, '/'.$bucket.'/'.$key);
            }
            $o->forceFill(['tags' => $this->parseTagging($request->getContent())])->save();

            return response('', 200);
        }

        // A copy is a PUT with no body and a source header. Without this branch
        // it looks like an empty upload and silently writes a 0-byte object
        // while the client reports success.
        if ($request->header('x-amz-copy-source')) {
            return $this->copyObject($request, $b, $key);
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
        $versioned = (bool) $b->versioning;
        $existing = $b->objects()->where('key', $key)->where('is_latest', true)->first();

        if ($this->storage->wouldExceedQuota($b, $size, $versioned ? null : $existing)) {
            @unlink($tmp);

            return S3Xml::error('QuotaExceeded', "The bucket \"{$b->name}\" quota would be exceeded.", '/'.$bucket.'/'.$key);
        }

        // Versioned: keep the old bytes and demote the previous version.
        // Unversioned: the write replaces what was there, so reclaim it.
        $versionId = $versioned ? $this->newVersionId() : 'null';
        if ($existing && ! $versioned) {
            $this->storage->delete($existing);
        }

        $path = $this->storage->pathFor($b, $key, $versionId);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        rename($tmp, $path);
        @chmod($path, 0664);

        if ($versioned) {
            $b->objects()->where('key', $key)->update(['is_latest' => false]);
        }

        $b->objects()->updateOrCreate(
            ['key' => $key, 'version_id' => $versionId],
            array_filter([
                'size_bytes' => $size,
                'content_type' => $request->header('Content-Type') ?: 'application/octet-stream',
                'etag' => $etag,
                'tags' => $this->headerTags($request),
                'is_latest' => true,
                'is_delete_marker' => false,
                'last_modified' => now(),
            ], fn ($v) => $v !== null) + $this->lockOnUpload($request, $b)
        );
        $b->refreshStats();

        $resp = response('', 200)->header('ETag', '"'.$etag.'"');

        return $versioned ? $resp->header('x-amz-version-id', $versionId) : $resp;
    }

    /** GET /{bucket}/{key} — GetObject, with Range support */
    public function getObject(Request $request, string $bucket, string $key)
    {
        // GetObjectRetention / GetObjectLegalHold share this route.
        if ($request->has('retention') || $request->has('legal-hold')) {
            [$b, $o, $err] = $this->findObject($request, $bucket, $key);
            if ($err) {
                return $err;
            }

            if ($request->has('legal-hold')) {
                return S3Xml::response(S3Xml::doc('LegalHold', [
                    'Status' => $o->legal_hold ? 'ON' : 'OFF',
                ]));
            }

            if (! $o->lock_mode) {
                return S3Xml::error('NoSuchObjectLockConfiguration', 'The object does not have a retention period.', '/'.$bucket.'/'.$key);
            }

            return S3Xml::response(S3Xml::doc('Retention', [
                'Mode' => $o->lock_mode,
                'RetainUntilDate' => optional($o->lock_retain_until)->toIso8601ZuluString(),
            ]));
        }

        // GetObjectTagging shares this route. Clients call it during ordinary
        // work (the AWS CLI reads tags before a multipart copy), so it must
        // answer properly rather than fall through to returning the object.
        if ($request->has('tagging')) {
            [$b, $o, $err] = $this->findObject($request, $bucket, $key);

            return $err ?: S3Xml::response($this->taggingXml($o->tags));
        }

        // ListParts shares this route, keyed off the uploadId query arg.
        if ($request->query('uploadId')) {
            $b = $this->findBucket($request, $bucket);

            return $b instanceof Bucket
                ? $this->listParts($request, $b, ltrim($key, '/'))
                : $b;
        }

        [$b, $o, $err] = $this->findObject($request, $bucket, $key);
        if ($err) {
            return $err;
        }

        $path = $this->storage->pathForObject($o);
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

        // AbortMultipartUpload shares this route.
        if ($request->query('uploadId')) {
            return $this->abortMultipartUpload($request, $b, ltrim($key, '/'));
        }

        // DeleteObjectTagging clears tags, it does not delete the object.
        if ($request->has('tagging')) {
            $o = $b->objects()->where('key', ltrim($key, '/'))->first();
            $o?->forceFill(['tags' => null])->save();

            return response('', 204);
        }

        $key = ltrim($key, '/');
        $versionId = (string) $request->query('versionId', '');

        // An explicit version is removed for good, including its bytes.
        if ($versionId !== '') {
            $o = $b->objects()->where('key', $key)->where('version_id', $versionId)->first();
            if ($o && $o->isLocked()) {
                // GOVERNANCE can be bypassed with the explicit header;
                // COMPLIANCE and legal holds cannot be bypassed at all.
                $bypass = strtolower((string) $request->header('x-amz-bypass-governance-retention')) === 'true';
                if ($o->legal_hold || $o->lock_mode === 'COMPLIANCE' || ! $bypass) {
                    return S3Xml::error('AccessDenied', 'Object is protected by an object lock.', '/'.$b->name.'/'.$key);
                }
            }
            if ($o) {
                $wasLatest = (bool) $o->is_latest;
                $this->storage->delete($o);
                $o->delete();
                if ($wasLatest) {
                    // Promote whatever version is now newest.
                    $b->objects()->where('key', $key)->latest('id')->first()
                        ?->forceFill(['is_latest' => true])->save();
                }
                $b->refreshStats();
            }

            return response('', 204)->header('x-amz-version-id', $versionId);
        }

        // Versioned bucket: a plain delete hides the key behind a delete marker
        // rather than destroying data, which is the point of versioning.
        if ($b->versioning) {
            $marker = $this->newVersionId();
            $b->objects()->where('key', $key)->update(['is_latest' => false]);
            $b->objects()->create([
                'key' => $key,
                'version_id' => $marker,
                'is_latest' => true,
                'is_delete_marker' => true,
                'size_bytes' => 0,
                'last_modified' => now(),
            ]);
            $b->refreshStats();

            return response('', 204)
                ->header('x-amz-delete-marker', 'true')
                ->header('x-amz-version-id', $marker);
        }

        $o = $b->objects()->where('key', $key)->first();
        if ($o) {
            $this->storage->delete($o);
            $o->delete();
            $b->refreshStats();
        }

        return response('', 204);
    }

    /**
     * PUT /{bucket}/{key} with x-amz-copy-source — CopyObject.
     *
     * Server-side copy: "aws s3 cp s3://a s3://b", "aws s3 mv", and mc's
     * copy/move all use it rather than round-tripping the bytes through the
     * client.
     */
    private function copyObject(Request $request, Bucket $dest, string $key)
    {
        $source = rawurldecode((string) $request->header('x-amz-copy-source'));
        $source = ltrim(strtok($source, '?'), '/');          // drop any ?versionId
        [$srcBucketName, $srcKey] = array_pad(explode('/', $source, 2), 2, '');

        if ($srcBucketName === '' || $srcKey === '') {
            return S3Xml::error('InvalidArgument', 'Malformed x-amz-copy-source.', '/'.$dest->name.'/'.$key);
        }

        $srcBucket = Bucket::where('name', $srcBucketName)->first();
        if (! $srcBucket) {
            return S3Xml::error('NoSuchBucket', null, '/'.$srcBucketName);
        }
        if (! $srcBucket->isVisibleTo($this->user($request))) {
            return S3Xml::error('AccessDenied', null, '/'.$srcBucketName);
        }

        $srcObject = $srcBucket->objects()->where('key', $srcKey)->current()->first();
        if (! $srcObject) {
            return S3Xml::error('NoSuchKey', null, '/'.$srcBucketName.'/'.$srcKey);
        }

        $srcPath = $this->storage->pathForObject($srcObject);
        if (! is_file($srcPath)) {
            return S3Xml::error('NoSuchKey', 'The stored data for the source object is no longer available.', '/'.$srcBucketName.'/'.$srcKey);
        }

        $size = (int) filesize($srcPath);
        $existing = $dest->objects()->where('key', $key)->first();

        if ($this->storage->wouldExceedQuota($dest, $size, $existing)) {
            return S3Xml::error('QuotaExceeded', "The bucket \"{$dest->name}\" quota would be exceeded.", '/'.$dest->name.'/'.$key);
        }

        // Copying a key onto itself is a metadata-only operation in S3; doing
        // the file copy would truncate the source before reading it.
        $destPath = $this->storage->pathFor($dest, $key);
        if ($srcPath !== $destPath) {
            if (! $this->storage->ensureDir(dirname($destPath))) {
                return S3Xml::error('InternalError', 'Could not create the destination directory.');
            }
            if (! @copy($srcPath, $destPath)) {
                return S3Xml::error('InternalError', 'Could not copy the object data.');
            }
        }

        $object = $dest->objects()->updateOrCreate(['key' => $key], [
            'size_bytes' => $size,
            'content_type' => $srcObject->content_type,
            'etag' => $srcObject->etag,
            'last_modified' => now(),
        ]);
        $dest->refreshStats();

        return S3Xml::response(S3Xml::doc('CopyObjectResult', [
            'LastModified' => $object->last_modified->toIso8601ZuluString(),
            'ETag' => '"'.$object->etag.'"',
        ]));
    }

    // -------------------------------------------------------------- multipart

    /**
     * POST /{bucket}/{key} — dispatches the two multipart POST operations,
     * which S3 tells apart by query string rather than by path.
     */
    public function postObject(Request $request, string $bucket, string $key)
    {
        $b = $this->findBucket($request, $bucket);
        if (! $b instanceof Bucket) {
            return $b;
        }

        $key = ltrim($key, '/');

        if ($request->has('uploads')) {
            return $this->createMultipartUpload($request, $b, $key);
        }

        if ($request->query('uploadId')) {
            return $this->completeMultipartUpload($request, $b, $key);
        }

        return S3Xml::error('NotImplemented', null, '/'.$bucket.'/'.$key);
    }

    /** POST /{bucket}/{key}?uploads — CreateMultipartUpload */
    private function createMultipartUpload(Request $request, Bucket $b, string $key)
    {
        $upload = MultipartUpload::create([
            'bucket_id' => $b->id,
            'user_id' => $this->user($request)?->id,
            'object_key' => $key,
            'upload_id' => bin2hex(random_bytes(24)),
            'content_type' => $request->header('Content-Type') ?: 'application/octet-stream',
        ]);

        return S3Xml::response(S3Xml::doc('InitiateMultipartUploadResult', [
            'Bucket' => $b->name,
            'Key' => $key,
            'UploadId' => $upload->upload_id,
        ]));
    }

    /** PUT /{bucket}/{key}?partNumber=N&uploadId=X — UploadPart (or UploadPartCopy) */
    private function uploadPart(Request $request, Bucket $b, string $key)
    {
        $upload = $this->findUpload($b, $key, (string) $request->query('uploadId'));
        if (! $upload) {
            return S3Xml::error('NoSuchUpload', 'The specified multipart upload does not exist.', '/'.$b->name.'/'.$key);
        }

        $partNumber = (int) $request->query('partNumber');
        if ($partNumber < 1 || $partNumber > 10000) {
            return S3Xml::error('InvalidPart', 'Part number must be between 1 and 10000.', '/'.$b->name.'/'.$key);
        }

        $dir = $this->storage->multipartDir($upload->upload_id);
        if (! $this->storage->ensureDir($dir)) {
            return S3Xml::error('InternalError', 'Could not stage the upload part.');
        }

        // UploadPartCopy: the part's bytes come from an existing object rather
        // than the request body. This is how clients copy objects too large for
        // a single CopyObject, and it supports an optional byte range.
        if ($request->header('x-amz-copy-source')) {
            return $this->uploadPartCopy($request, $upload, $partNumber);
        }

        $path = $this->storage->partPath($upload->upload_id, $partNumber);
        $out = fopen($path, 'w+b');
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

        $etag = md5_file($path) ?: '';

        // Re-uploading a part replaces it; SDKs retry parts on network errors.
        MultipartPart::updateOrCreate(
            ['multipart_upload_id' => $upload->id, 'part_number' => $partNumber],
            ['size_bytes' => $size, 'etag' => $etag]
        );

        return response('', 200)->header('ETag', '"'.$etag.'"');
    }

    /**
     * UploadPartCopy — a part sourced from an existing object, optionally a
     * byte range of it (x-amz-copy-source-range: bytes=0-1048575).
     */
    private function uploadPartCopy(Request $request, MultipartUpload $upload, int $partNumber)
    {
        $source = rawurldecode((string) $request->header('x-amz-copy-source'));
        $source = ltrim(strtok($source, '?'), '/');
        [$srcBucketName, $srcKey] = array_pad(explode('/', $source, 2), 2, '');

        $srcBucket = Bucket::where('name', $srcBucketName)->first();
        if (! $srcBucket) {
            return S3Xml::error('NoSuchBucket', null, '/'.$srcBucketName);
        }
        if (! $srcBucket->isVisibleTo($this->user($request))) {
            return S3Xml::error('AccessDenied', null, '/'.$srcBucketName);
        }

        $srcObject = $srcBucket->objects()->where('key', $srcKey)->current()->first();
        $srcPath = $srcObject ? $this->storage->pathForObject($srcObject) : null;
        if (! $srcObject || ! $srcPath || ! is_file($srcPath)) {
            return S3Xml::error('NoSuchKey', null, '/'.$srcBucketName.'/'.$srcKey);
        }

        $total = (int) filesize($srcPath);
        $start = 0;
        $length = $total;

        if (preg_match('/bytes=(\d+)-(\d*)/', (string) $request->header('x-amz-copy-source-range'), $m)) {
            $start = (int) $m[1];
            $end = $m[2] === '' ? $total - 1 : min((int) $m[2], $total - 1);
            if ($start > $end || $start >= $total) {
                return S3Xml::error('InvalidArgument', 'The copy source range is not valid.');
            }
            $length = $end - $start + 1;
        }

        $path = $this->storage->partPath($upload->upload_id, $partNumber);
        $in = fopen($srcPath, 'rb');
        $out = fopen($path, 'w+b');
        fseek($in, $start);
        stream_copy_to_stream($in, $out, $length);
        fclose($in);
        fclose($out);

        $etag = md5_file($path) ?: '';

        MultipartPart::updateOrCreate(
            ['multipart_upload_id' => $upload->id, 'part_number' => $partNumber],
            ['size_bytes' => (int) filesize($path), 'etag' => $etag]
        );

        return S3Xml::response(S3Xml::doc('CopyPartResult', [
            'LastModified' => now()->toIso8601ZuluString(),
            'ETag' => '"'.$etag.'"',
        ]));
    }

    /**
     * POST /{bucket}/{key}?uploadId=X — CompleteMultipartUpload.
     *
     * Concatenates the parts the client lists, in the order it lists them, and
     * returns the S3-style composite etag: the MD5 of the concatenated binary
     * part MD5s, suffixed with the part count. Clients compare against it, so
     * it has to be computed exactly this way rather than as a hash of the file.
     */
    private function completeMultipartUpload(Request $request, Bucket $b, string $key)
    {
        $upload = $this->findUpload($b, $key, (string) $request->query('uploadId'));
        if (! $upload) {
            return S3Xml::error('NoSuchUpload', 'The specified multipart upload does not exist.', '/'.$b->name.'/'.$key);
        }

        $wanted = $this->parsePartList($request->getContent());
        $parts = $upload->parts()->get()->keyBy('part_number');

        if ($wanted === []) {
            $wanted = $parts->keys()->all(); // tolerate an empty/unparsable body
        }

        $total = 0;
        foreach ($wanted as $n) {
            if (! isset($parts[$n])) {
                return S3Xml::error('InvalidPart', "Part {$n} was never uploaded.", '/'.$b->name.'/'.$key);
            }
            $total += (int) $parts[$n]->size_bytes;
        }

        $existing = $b->objects()->where('key', $key)->first();
        if ($this->storage->wouldExceedQuota($b, $total, $existing)) {
            $this->storage->discardMultipart($upload->upload_id);
            $upload->delete();

            return S3Xml::error('QuotaExceeded', "The bucket \"{$b->name}\" quota would be exceeded.", '/'.$b->name.'/'.$key);
        }

        $final = $this->storage->pathFor($b, $key);
        if (! $this->storage->ensureDir(dirname($final))) {
            return S3Xml::error('InternalError', 'Could not create the destination directory.');
        }

        // Stream part-by-part rather than reading them into memory: a 100 GB
        // object must not need 100 GB of RAM to assemble.
        $out = fopen($final, 'w+b');
        $binaryMd5s = '';
        foreach ($wanted as $n) {
            $pp = $this->storage->partPath($upload->upload_id, (int) $n);
            $in = fopen($pp, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            $binaryMd5s .= hex2bin((string) $parts[$n]->etag) ?: '';
        }
        fclose($out);

        $etag = md5($binaryMd5s).'-'.count($wanted);

        if ($existing) {
            // The old bytes were already replaced on disk by the write above,
            // so only the row needs updating.
            $existing->fill([
                'size_bytes' => $total,
                'content_type' => $upload->content_type,
                'etag' => $etag,
                'last_modified' => now(),
            ])->save();
        } else {
            $b->objects()->create([
                'key' => $key,
                'size_bytes' => $total,
                'content_type' => $upload->content_type,
                'etag' => $etag,
                'last_modified' => now(),
            ]);
        }

        $this->storage->discardMultipart($upload->upload_id);
        $upload->delete();
        $b->refreshStats();

        return S3Xml::response(S3Xml::doc('CompleteMultipartUploadResult', [
            'Location' => $request->getSchemeAndHttpHost().'/'.$b->name.'/'.$key,
            'Bucket' => $b->name,
            'Key' => $key,
            'ETag' => '"'.$etag.'"',
        ]));
    }

    /** DELETE /{bucket}/{key}?uploadId=X — AbortMultipartUpload */
    private function abortMultipartUpload(Request $request, Bucket $b, string $key)
    {
        $upload = $this->findUpload($b, $key, (string) $request->query('uploadId'));
        if ($upload) {
            $this->storage->discardMultipart($upload->upload_id);
            $upload->delete();
        }

        return response('', 204);
    }

    /** GET /{bucket}/{key}?uploadId=X — ListParts */
    private function listParts(Request $request, Bucket $b, string $key)
    {
        $upload = $this->findUpload($b, $key, (string) $request->query('uploadId'));
        if (! $upload) {
            return S3Xml::error('NoSuchUpload', null, '/'.$b->name.'/'.$key);
        }

        $items = '';
        foreach ($upload->parts as $p) {
            $items .= '<Part>'
                .'<PartNumber>'.(int) $p->part_number.'</PartNumber>'
                .'<LastModified>'.$p->updated_at->toIso8601ZuluString().'</LastModified>'
                .'<ETag>&quot;'.S3Xml::esc((string) $p->etag).'&quot;</ETag>'
                .'<Size>'.(int) $p->size_bytes.'</Size>'
                .'</Part>';
        }

        return S3Xml::response(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<ListPartsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            .'<Bucket>'.S3Xml::esc($b->name).'</Bucket>'
            .'<Key>'.S3Xml::esc($key).'</Key>'
            .'<UploadId>'.S3Xml::esc($upload->upload_id).'</UploadId>'
            .'<IsTruncated>false</IsTruncated>'
            .$items
            .'</ListPartsResult>'
        );
    }

    /** GET /{bucket}?uploads — ListMultipartUploads (mc probes this) */
    private function listMultipartUploads(Bucket $b)
    {
        $items = '';
        foreach (MultipartUpload::where('bucket_id', $b->id)->get() as $u) {
            $items .= '<Upload>'
                .'<Key>'.S3Xml::esc($u->object_key).'</Key>'
                .'<UploadId>'.S3Xml::esc($u->upload_id).'</UploadId>'
                .'<Initiated>'.$u->created_at->toIso8601ZuluString().'</Initiated>'
                .'</Upload>';
        }

        return S3Xml::response(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<ListMultipartUploadsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            .'<Bucket>'.S3Xml::esc($b->name).'</Bucket>'
            .'<IsTruncated>false</IsTruncated>'
            .$items
            .'</ListMultipartUploadsResult>'
        );
    }

    private function findUpload(Bucket $b, string $key, string $uploadId): ?MultipartUpload
    {
        return MultipartUpload::where('bucket_id', $b->id)
            ->where('object_key', $key)
            ->where('upload_id', $uploadId)
            ->first();
    }

    /** Pull the part numbers out of a CompleteMultipartUpload body, in order. */
    private function parsePartList(string $xml): array
    {
        if (trim($xml) === '') {
            return [];
        }

        if (preg_match_all('/<PartNumber>\s*(\d+)\s*<\/PartNumber>/i', $xml, $m)) {
            return array_map('intval', $m[1]);
        }

        return [];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * GET /{bucket}?versions — ListObjectVersions.
     *
     * Every version of every key, with delete markers reported separately so
     * clients can distinguish "hidden" from "never existed".
     */
    private function listObjectVersions(Request $request, Bucket $b)
    {
        $prefix = (string) $request->query('prefix', '');
        $maxKeys = max(1, min(1000, (int) $request->query('max-keys', 1000)));

        $query = $b->objects()->orderBy('key')->orderByDesc('id');
        if ($prefix !== '') {
            $query->where('key', 'like', str_replace(['%', '_'], ['\%', '\_'], $prefix).'%');
        }

        $body = '';
        foreach ($query->limit($maxKeys)->get() as $o) {
            $common = '<Key>'.S3Xml::esc($o->key).'</Key>'
                .'<VersionId>'.S3Xml::esc((string) $o->version_id).'</VersionId>'
                .'<IsLatest>'.($o->is_latest ? 'true' : 'false').'</IsLatest>'
                .'<LastModified>'.($o->last_modified ?? $o->updated_at)->toIso8601ZuluString().'</LastModified>';

            $body .= $o->is_delete_marker
                ? '<DeleteMarker>'.$common.'</DeleteMarker>'
                : '<Version>'.$common
                    .'<ETag>&quot;'.S3Xml::esc((string) $o->etag).'&quot;</ETag>'
                    .'<Size>'.(int) $o->size_bytes.'</Size>'
                    .'<StorageClass>STANDARD</StorageClass></Version>';
        }

        return S3Xml::response(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<ListVersionsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            .'<Name>'.S3Xml::esc($b->name).'</Name>'
            .'<Prefix>'.S3Xml::esc($prefix).'</Prefix>'
            .'<MaxKeys>'.$maxKeys.'</MaxKeys>'
            .'<IsTruncated>false</IsTruncated>'
            .$body
            .'</ListVersionsResult>'
        );
    }

    /**
     * Lock state for a newly uploaded object: explicit x-amz-object-lock-*
     * headers win, otherwise the bucket's default retention applies.
     *
     * @return array<string,mixed>
     */
    private function lockOnUpload(Request $request, Bucket $b): array
    {
        $mode = strtoupper((string) $request->header('x-amz-object-lock-mode'));
        $until = (string) $request->header('x-amz-object-lock-retain-until-date');
        $hold = strtoupper((string) $request->header('x-amz-object-lock-legal-hold')) === 'ON';

        if ($mode === '' && $until === '' && ! $hold && $b->object_lock_enabled && $b->default_lock_mode) {
            return [
                'lock_mode' => $b->default_lock_mode,
                'lock_retain_until' => now()->addDays((int) ($b->default_lock_days ?: 0)),
            ];
        }

        $out = [];
        if ($mode !== '') {
            $out['lock_mode'] = $mode;
        }
        if ($until !== '' && strtotime($until)) {
            $out['lock_retain_until'] = date('Y-m-d H:i:s', strtotime($until));
        }
        if ($hold) {
            $out['legal_hold'] = true;
        }

        return $out;
    }

    /** Render a tag set as S3's Tagging document (empty set is valid). */
    private function taggingXml($tags): string
    {
        $items = '';
        foreach ((array) ($tags ?? []) as $k => $v) {
            $items .= '<Tag><Key>'.S3Xml::esc((string) $k).'</Key><Value>'.S3Xml::esc((string) $v).'</Value></Tag>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Tagging xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><TagSet>'.$items.'</TagSet></Tagging>';
    }

    /** Parse a Tagging document into a key => value map. */
    private function parseTagging(string $xml): array
    {
        $tags = [];
        if (preg_match_all('/<Tag>\s*<Key>(.*?)<\/Key>\s*<Value>(.*?)<\/Value>\s*<\/Tag>/s', $xml, $m, PREG_SET_ORDER)) {
            foreach ($m as $tag) {
                $tags[html_entity_decode($tag[1], ENT_XML1 | ENT_QUOTES, 'UTF-8')]
                    = html_entity_decode($tag[2], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            }
        }

        return $tags;
    }

    /** Tags sent inline with an upload: x-amz-tagging: a=1&b=2 */
    private function headerTags(Request $request): ?array
    {
        $raw = (string) $request->header('x-amz-tagging');
        if ($raw === '') {
            return null;
        }
        parse_str($raw, $tags);

        return $tags ?: null;
    }

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

        $key = ltrim($key, '/');
        $versionId = (string) $request->query('versionId', '');

        $o = $versionId !== ''
            ? $b->objects()->where('key', $key)->where('version_id', $versionId)->first()
            : $b->objects()->where('key', $key)->where('is_latest', true)->first();

        if (! $o) {
            return [$b, null, S3Xml::error('NoSuchKey', null, '/'.$bucket.'/'.$key)];
        }

        // A key whose newest version is a delete marker reads as absent, and S3
        // signals that specifically so clients can tell it apart.
        if ($o->is_delete_marker && $versionId === '') {
            return [$b, null, S3Xml::error('NoSuchKey', null, '/'.$bucket.'/'.$key)
                ->header('x-amz-delete-marker', 'true')];
        }

        return [$b, $o, null];
    }

    /** S3 version ids are opaque; ours sort lexicographically by creation. */
    private function newVersionId(): string
    {
        return str_pad((string) (int) (microtime(true) * 1000), 14, '0', STR_PAD_LEFT).bin2hex(random_bytes(6));
    }
}
