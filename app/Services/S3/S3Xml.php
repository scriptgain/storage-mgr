<?php

namespace App\Services\S3;

use Illuminate\Http\Response;

/**
 * S3 speaks XML with specific element names and an expected error shape. SDKs
 * parse these strictly — a wrong root element reads as a corrupt response, and
 * a missing error Code makes clients retry instead of failing fast.
 */
class S3Xml
{
    /** HTTP status for each S3 error code we emit. */
    private const STATUS = [
        'NoSuchBucket' => 404,
        'NoSuchKey' => 404,
        'BucketAlreadyExists' => 409,
        'BucketAlreadyOwnedByYou' => 409,
        'BucketNotEmpty' => 409,
        'AccessDenied' => 403,
        'InvalidAccessKeyId' => 403,
        'SignatureDoesNotMatch' => 403,
        'AuthorizationHeaderMalformed' => 400,
        'AuthorizationQueryParametersError' => 400,
        'InvalidBucketName' => 400,
        'EntityTooLarge' => 400,
        'QuotaExceeded' => 400,
        'NotImplemented' => 501,
        'InternalError' => 500,
        'NoSuchUpload' => 404,
        'InvalidPart' => 400,
    ];

    public static function error(string $code, ?string $message = null, ?string $resource = null): Response
    {
        $status = self::STATUS[$code] ?? 400;

        $xml = self::doc('Error', [
            'Code' => $code,
            'Message' => $message ?: self::defaultMessage($code),
            'Resource' => $resource ?: '',
            'RequestId' => bin2hex(random_bytes(8)),
        ]);

        return response($xml, $status)->header('Content-Type', 'application/xml');
    }

    public static function response(string $xml, int $status = 200): Response
    {
        return response($xml, $status)->header('Content-Type', 'application/xml');
    }

    /** Build a document from a flat map of element => value. */
    public static function doc(string $root, array $children): string
    {
        $body = '';
        foreach ($children as $name => $value) {
            $body .= '<'.$name.'>'.htmlspecialchars((string) $value, ENT_XML1).'</'.$name.'>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'.
            '<'.$root.' xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'.$body.'</'.$root.'>';
    }

    public static function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_XML1);
    }

    private static function defaultMessage(string $code): string
    {
        return match ($code) {
            'NoSuchBucket' => 'The specified bucket does not exist.',
            'NoSuchKey' => 'The specified key does not exist.',
            'BucketAlreadyExists' => 'The requested bucket name is not available.',
            'BucketAlreadyOwnedByYou' => 'You already own this bucket.',
            'BucketNotEmpty' => 'The bucket you tried to delete is not empty.',
            'AccessDenied' => 'Access Denied.',
            'InvalidAccessKeyId' => 'The access key ID you provided does not exist in our records.',
            'SignatureDoesNotMatch' => 'The request signature we calculated does not match the signature you provided.',
            'InvalidBucketName' => 'The specified bucket is not valid.',
            'QuotaExceeded' => 'The bucket quota would be exceeded by this request.',
            'NotImplemented' => 'This operation is not implemented by this server.',
            default => 'The request could not be completed.',
        };
    }
}
