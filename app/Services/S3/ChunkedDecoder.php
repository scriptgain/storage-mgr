<?php

namespace App\Services\S3;

/**
 * Decodes aws-chunked transfer encoding.
 *
 * When a client sends x-amz-content-sha256: STREAMING-AWS4-HMAC-SHA256-PAYLOAD
 * the body is not the file — it is the file cut into frames, each prefixed with
 * a hex length and its own chunk signature:
 *
 *     <hex-length>;chunk-signature=<64 hex>\r\n<payload>\r\n...0;chunk-signature=...\r\n\r\n
 *
 * Writing that to disk verbatim corrupts every upload with framing bytes, which
 * is the classic failure when adding S3 support. This strips the frames back to
 * the original bytes.
 */
class ChunkedDecoder
{
    public static function isChunked(\Illuminate\Http\Request $request): bool
    {
        $declared = (string) $request->header('x-amz-content-sha256');

        return in_array($declared, [SignatureV4::STREAMING, SignatureV4::STREAMING_UNSIGNED], true)
            || str_contains(strtolower((string) $request->header('content-encoding')), 'aws-chunked');
    }

    /** Stream-decode $in into $out, returning the number of payload bytes written. */
    public static function decodeStream($in, $out): int
    {
        $written = 0;

        while (! feof($in)) {
            $header = self::readLine($in);
            if ($header === null || $header === '') {
                break;
            }

            // "<hex-size>" optionally followed by ";chunk-signature=..."
            $size = (int) hexdec(strtok($header, ';'));
            if ($size <= 0) {
                break; // terminating 0-length chunk (trailers, if any, follow)
            }

            $remaining = $size;
            while ($remaining > 0 && ! feof($in)) {
                $buf = fread($in, min(8192, $remaining));
                if ($buf === false || $buf === '') {
                    break;
                }
                fwrite($out, $buf);
                $written += strlen($buf);
                $remaining -= strlen($buf);
            }

            self::readLine($in); // trailing CRLF after the chunk payload
        }

        return $written;
    }

    /** Read one CRLF-terminated line without over-reading into the payload. */
    private static function readLine($in): ?string
    {
        $line = '';
        while (! feof($in)) {
            $c = fread($in, 1);
            if ($c === false || $c === '') {
                return $line === '' ? null : $line;
            }
            if ($c === "\n") {
                return rtrim($line, "\r");
            }
            $line .= $c;
            if (strlen($line) > 4096) {
                return $line; // malformed; refuse to buffer forever
            }
        }

        return $line === '' ? null : $line;
    }
}
