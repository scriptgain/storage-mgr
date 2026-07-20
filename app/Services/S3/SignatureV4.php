<?php

namespace App\Services\S3;

use App\Models\AccessKey;
use Illuminate\Http\Request;

/**
 * Verifies AWS Signature Version 4 on incoming S3 requests.
 *
 * The client and server independently build the same canonical string from the
 * request and HMAC it with a key derived from the secret; if our signature
 * matches theirs, the request is authentic and untampered. This is why access
 * key secrets must be stored recoverably — HMAC needs the original secret, so
 * no S3-compatible server can hash them the way it hashes passwords.
 *
 * Supports both the presigned-URL form (credentials in the query string) and
 * the Authorization-header form that SDKs and the CLI use by default.
 */
class SignatureV4
{
    public const ALGORITHM = 'AWS4-HMAC-SHA256';

    /** Payload hashes that mean "don't hash the body". */
    public const UNSIGNED = 'UNSIGNED-PAYLOAD';
    public const STREAMING = 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD';
    public const STREAMING_UNSIGNED = 'STREAMING-UNSIGNED-PAYLOAD-TRAILER';

    /**
     * Resolve and verify the caller. Returns the matching AccessKey, or an S3
     * error code string describing why it failed.
     *
     * @return AccessKey|string
     */
    public function verify(Request $request)
    {
        $parsed = $this->parse($request);
        if (is_string($parsed)) {
            return $parsed;
        }

        [$keyId, $scope, $signedHeaders, $signature, $isPresigned] = $parsed;

        $key = AccessKey::where('access_key_id', $keyId)->first();
        if (! $key) {
            return 'InvalidAccessKeyId';
        }
        if ($key->status !== 'active') {
            return 'AccessDenied';
        }

        $expected = $this->sign($request, $key->secret_key, $scope, $signedHeaders, $isPresigned);

        // Constant-time compare: a timing-variable check on a signature is a
        // classic way to let an attacker recover it byte by byte.
        if (! hash_equals($expected, $signature)) {
            return 'SignatureDoesNotMatch';
        }

        return $key;
    }

    /**
     * Pull the credential scope, signed header list and signature out of either
     * the Authorization header or the presigned query parameters.
     *
     * @return array{0:string,1:string,2:array<int,string>,3:string,4:bool}|string
     */
    private function parse(Request $request)
    {
        if ($request->query('X-Amz-Signature')) {
            $cred = (string) $request->query('X-Amz-Credential');
            if (! str_contains($cred, '/')) {
                return 'AuthorizationQueryParametersError';
            }
            [$keyId, $scope] = explode('/', $cred, 2);

            return [
                $keyId,
                $scope,
                explode(';', strtolower((string) $request->query('X-Amz-SignedHeaders'))),
                (string) $request->query('X-Amz-Signature'),
                true,
            ];
        }

        $header = (string) $request->header('Authorization');
        if ($header === '' || ! str_starts_with($header, self::ALGORITHM)) {
            return 'AccessDenied';
        }

        // AWS4-HMAC-SHA256 Credential=AKIA.../20260720/us-east-1/s3/aws4_request, SignedHeaders=host;x-amz-date, Signature=abc...
        if (! preg_match('/Credential=([^\/]+)\/([^,]+),\s*SignedHeaders=([^,]+),\s*Signature=([0-9a-f]+)/i', $header, $m)) {
            return 'AuthorizationHeaderMalformed';
        }

        return [$m[1], trim($m[2]), explode(';', strtolower($m[3])), strtolower($m[4]), false];
    }

    /** Build the signature we expect for this request. */
    private function sign(Request $request, string $secret, string $scope, array $signedHeaders, bool $isPresigned): string
    {
        $canonical = implode("\n", [
            $request->getMethod(),
            $this->canonicalUri($request),
            $this->canonicalQuery($request, $isPresigned),
            $this->canonicalHeaders($request, $signedHeaders),
            implode(';', $signedHeaders),
            $this->payloadHash($request, $isPresigned),
        ]);

        $timestamp = $isPresigned
            ? (string) $request->query('X-Amz-Date')
            : (string) ($request->header('x-amz-date') ?: $request->header('date'));

        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $timestamp,
            $scope,
            hash('sha256', $canonical),
        ]);

        [$date, $region, $service] = array_pad(explode('/', $scope), 3, '');

        $k = hash_hmac('sha256', $date, 'AWS4'.$secret, true);
        $k = hash_hmac('sha256', $region, $k, true);
        $k = hash_hmac('sha256', $service, $k, true);
        $k = hash_hmac('sha256', 'aws4_request', $k, true);

        return hash_hmac('sha256', $stringToSign, $k);
    }

    /**
     * The path, URI-encoded per segment with slashes preserved. S3 (unlike other
     * AWS services) does NOT double-encode here.
     */
    private function canonicalUri(Request $request): string
    {
        // Under virtual-host addressing the bucket is folded into the path
        // before routing; the client signed the path without it.
        $path = (string) ($request->attributes->get('s3_signed_path') ?: $request->getPathInfo() ?: '/');

        $segments = array_map(
            fn ($s) => str_replace(['+', '%2F', '%7E'], ['%20', '/', '~'], rawurlencode(rawurldecode($s))),
            explode('/', $path)
        );

        return implode('/', $segments);
    }

    /**
     * Query parameters sorted by name, URI-encoded, excluding the signature.
     *
     * Built from the RAW query string on purpose. Reading $request->query would
     * be wrong twice over: Laravel's global ConvertEmptyStringsToNull rewrites
     * empty values to null, and PHP's parser mangles some names. Clients sign
     * what they sent, so anything that edits the parameters on the way in makes
     * every signature fail — "?prefix=" and valueless flags like "?uploads"
     * (which S3 uses heavily) are exactly the cases that break.
     */
    private function canonicalQuery(Request $request, bool $isPresigned): string
    {
        $raw = (string) $request->server->get('QUERY_STRING', '');
        if ($raw === '') {
            return '';
        }

        $pairs = [];
        foreach (explode('&', $raw) as $part) {
            if ($part === '') {
                continue;
            }

            $eq = strpos($part, '=');
            $name = $eq === false ? rawurldecode($part) : rawurldecode(substr($part, 0, $eq));
            $value = $eq === false ? '' : rawurldecode(substr($part, $eq + 1));

            if ($isPresigned && $name === 'X-Amz-Signature') {
                continue;
            }

            $pairs[] = [rawurlencode($name), rawurlencode($value)];
        }

        // Sort by encoded name, then encoded value, as the spec requires.
        usort($pairs, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(fn ($p) => $p[0].'='.$p[1], $pairs));
    }

    /** Only the headers the client said it signed, lowercased and whitespace-collapsed. */
    private function canonicalHeaders(Request $request, array $signedHeaders): string
    {
        $out = '';
        foreach ($signedHeaders as $name) {
            $name = strtolower(trim($name));
            $value = $name === 'host'
                ? (string) ($request->attributes->get('s3_signed_host') ?: $request->getHttpHost())
                : (string) $request->header($name);

            $out .= $name.':'.preg_replace('/\s+/', ' ', trim($value))."\n";
        }

        return $out;
    }

    /**
     * The body hash the client claims. Presigned URLs and the streaming/unsigned
     * sentinels are used verbatim rather than hashing the body, which is what
     * lets large uploads stream without buffering.
     */
    private function payloadHash(Request $request, bool $isPresigned): string
    {
        $declared = (string) $request->header('x-amz-content-sha256');

        if ($isPresigned && $declared === '') {
            return self::UNSIGNED;
        }

        return $declared !== '' ? $declared : hash('sha256', $request->getContent());
    }
}
