<?php

namespace App\Http\Middleware;

use App\Services\S3\PolicyEvaluator;
use App\Services\S3\S3Xml;
use Closure;
use Illuminate\Http\Request;

/**
 * Applies the IAM policy attached to the calling access key.
 *
 * Keys with no policy keep the previous behaviour (full access to whatever
 * their owner can see), so attaching a policy is what narrows a key — existing
 * credentials are never silently broadened or broken.
 *
 * Runs after SigV4 authentication: we must know who is calling before deciding
 * what they may do.
 */
class EnforceS3Policy
{
    public function __construct(private readonly PolicyEvaluator $evaluator) {}

    public function handle(Request $request, Closure $next)
    {
        $key = $request->attributes->get('s3_access_key');
        $policy = $key?->policy?->document;

        if (! $key || ! $policy) {
            return $next($request);
        }

        [$action, $resource] = $this->describe($request);

        if (! $this->evaluator->allows($policy, $action, $resource)) {
            return S3Xml::error(
                'AccessDenied',
                "The access key's policy does not permit {$action} on this resource.",
                $request->getPathInfo()
            );
        }

        return $next($request);
    }

    /**
     * Work out which S3 action this request represents, and the ARN it targets.
     *
     * @return array{0:string,1:string}
     */
    private function describe(Request $request): array
    {
        $path = trim($request->getPathInfo(), '/');
        $prefix = trim((string) config('storage.s3_prefix', 's3'), '/');

        // Path-style requests arrive under the /s3 prefix; strip it so the
        // bucket is the first segment either way.
        if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix.'/'))) {
            $path = ltrim(substr($path, strlen($prefix)), '/');
        }

        // Virtual-host style puts the bucket in the domain, not the path.
        $vhostBucket = $request->route('bucket');

        if ($vhostBucket && ! str_starts_with($path, $vhostBucket)) {
            $bucket = (string) $vhostBucket;
            $key = $path;
        } else {
            [$bucket, $key] = array_pad(explode('/', $path, 2), 2, '');
        }

        $method = $request->getMethod();

        if ($bucket === '') {
            return ['s3:ListAllMyBuckets', 'arn:aws:s3:::*'];
        }

        $bucketArn = 'arn:aws:s3:::'.$bucket;

        if ($key === '') {
            $action = match ($method) {
                'PUT' => $request->has('tagging') || $request->has('versioning')
                    || $request->has('lifecycle') || $request->has('object-lock')
                        ? 's3:PutBucketConfiguration' : 's3:CreateBucket',
                'DELETE' => 's3:DeleteBucket',
                'POST' => 's3:DeleteObject',   // bulk delete
                default => 's3:ListBucket',
            };

            // Bulk delete targets objects, so it is judged against the objects.
            return [$action, $action === 's3:DeleteObject' ? $bucketArn.'/*' : $bucketArn];
        }

        $action = match ($method) {
            'PUT', 'POST' => $request->has('tagging') ? 's3:PutObjectTagging' : 's3:PutObject',
            'DELETE' => 's3:DeleteObject',
            'HEAD' => 's3:GetObject',
            default => $request->has('tagging') ? 's3:GetObjectTagging' : 's3:GetObject',
        };

        return [$action, $bucketArn.'/'.$key];
    }
}
