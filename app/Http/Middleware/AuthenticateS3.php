<?php

namespace App\Http\Middleware;

use App\Services\S3\S3Xml;
use App\Services\S3\SignatureV4;
use Closure;
use Illuminate\Http\Request;

/**
 * Authenticates S3 protocol requests with SigV4 and puts the resolved AccessKey
 * (and its owner) on the request for the controllers to authorise against.
 */
class AuthenticateS3
{
    public function __construct(private readonly SignatureV4 $signer) {}

    public function handle(Request $request, Closure $next)
    {
        $result = $this->signer->verify($request);

        if (is_string($result)) {
            return S3Xml::error($result, null, $request->getPathInfo());
        }

        $request->attributes->set('s3_access_key', $result);
        $request->attributes->set('s3_user', $result->owner);

        // Useful operationally: shows which credentials are actually in use.
        $result->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }
}
