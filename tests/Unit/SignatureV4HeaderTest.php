<?php

namespace Tests\Unit;

use App\Services\S3\SignatureV4;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * A request that arrives with no Authorization header is nearly always a server
 * configuration fault rather than a client one.
 *
 * nginx does not pass Authorization to FastCGI on its own and fastcgi_params
 * does not define it, so a vhost missing
 *
 *     fastcgi_param HTTP_AUTHORIZATION $http_authorization;
 *
 * rejects every signed request while presigned URLs, which carry their
 * credentials in the query string, keep working perfectly. That combination is
 * genuinely confusing: the browser and every presigned download succeed, and
 * every real S3 client fails.
 *
 * It used to return a bare AccessDenied, identical to the code returned for a
 * disabled key, which sends the operator looking for a credential or policy
 * problem that does not exist. It took reading the source to tell the two apart.
 */
class SignatureV4HeaderTest extends TestCase
{
    public function test_a_missing_authorization_header_is_not_reported_as_access_denied(): void
    {
        $signer = new SignatureV4();

        $result = $signer->verify(Request::create('/hostmgr002-backups/.storageconfig', 'GET'));

        $this->assertSame('MissingSecurityHeader', $result);
        $this->assertNotSame(
            'AccessDenied',
            $result,
            'AccessDenied here is indistinguishable from a disabled key, which is what made this hard to diagnose'
        );
    }

    public function test_a_header_that_is_not_sigv4_is_reported_as_malformed(): void
    {
        $signer = new SignatureV4();

        $request = Request::create('/bucket/key', 'GET');
        $request->headers->set('Authorization', 'Basic dXNlcjpwYXNz');

        $this->assertSame('AuthorizationHeaderMalformed', $signer->verify($request));
    }
}
