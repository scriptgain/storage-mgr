<?php

namespace App\Services\S3;

/**
 * Server-side encryption at rest (SSE-S3), AES-256-CBC with per-object keys.
 *
 * Design notes that matter for it to be worth anything:
 *
 *  - The master key comes from config (STORAGE_ENCRYPTION_KEY), falling back to
 *    the app key. It is never written next to the data, so someone who walks
 *    off with the object directory alone gets ciphertext.
 *  - Every object gets its own key, derived from the master key and the
 *    object's identity via HKDF. Reusing one key across every object would mean
 *    a single compromise exposes everything.
 *  - Streamed in chunks so a large object never has to fit in memory.
 *  - Encryption is per-object state: enabling it later does not strand
 *    previously written plaintext, since each object records whether it is
 *    encrypted.
 */
class ObjectCipher
{
    private const CIPHER = 'aes-256-cbc';

    private const CHUNK = 1048576; // 1 MiB of plaintext per block

    public function enabled(): bool
    {
        return (bool) config('storage.encryption');
    }

    /** Master key material; never stored alongside objects. */
    private function masterKey(): string
    {
        $key = (string) (config('storage.encryption_key') ?: config('app.key'));

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7)) ?: $key;
        }

        return $key;
    }

    /** Per-object key: one compromise must not unlock every other object. */
    private function objectKey(string $context): string
    {
        return hash_hkdf('sha256', $this->masterKey(), 32, 'storagemgr-object:'.$context);
    }

    /**
     * Encrypt $in into $out. Each chunk carries its own IV, so the stream can be
     * written and read without holding the object in memory.
     *
     * @return int bytes of plaintext processed
     */
    public function encryptStream($in, $out, string $context): int
    {
        $key = $this->objectKey($context);
        $plain = 0;

        while (! feof($in)) {
            $buf = fread($in, self::CHUNK);
            if ($buf === false || $buf === '') {
                break;
            }
            $plain += strlen($buf);

            $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
            $enc = openssl_encrypt($buf, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

            // Length prefix + IV + ciphertext, so decryption needs no metadata.
            fwrite($out, pack('N', strlen($enc)).$iv.$enc);
        }

        return $plain;
    }

    /** Decrypt $in into $out (or echo it when $out is null). */
    public function decryptStream($in, $out, string $context): void
    {
        $key = $this->objectKey($context);
        $ivLen = openssl_cipher_iv_length(self::CIPHER);

        while (! feof($in)) {
            $header = fread($in, 4);
            if ($header === false || strlen($header) < 4) {
                break;
            }
            $len = unpack('N', $header)[1];
            $iv = fread($in, $ivLen);
            $enc = $len > 0 ? fread($in, $len) : '';

            $plain = openssl_decrypt((string) $enc, self::CIPHER, $key, OPENSSL_RAW_DATA, (string) $iv);
            if ($plain === false) {
                return; // corrupt or wrong key: stop rather than emit garbage
            }

            if ($out === null) {
                echo $plain;
                flush();
            } else {
                fwrite($out, $plain);
            }
        }
    }

    /**
     * Emit only [$start, $start+$length) of the plaintext.
     *
     * Blocks are walked in order and discarded until the window is reached, so
     * memory stays flat even for a large object. Ranges must work here: clients
     * use them to resume interrupted downloads, and returning the wrong bytes
     * would corrupt a resumed file silently.
     */
    public function decryptRangeStream($in, string $context, int $start, int $length, $out = null): void
    {
        $key = $this->objectKey($context);
        $ivLen = openssl_cipher_iv_length(self::CIPHER);
        $pos = 0;
        $left = $length;

        while ($left > 0 && ! feof($in)) {
            $header = fread($in, 4);
            if ($header === false || strlen($header) < 4) {
                return;
            }
            $len = unpack('N', $header)[1];
            $iv = fread($in, $ivLen);
            $enc = $len > 0 ? fread($in, $len) : '';

            $plain = openssl_decrypt((string) $enc, self::CIPHER, $key, OPENSSL_RAW_DATA, (string) $iv);
            if ($plain === false) {
                return;
            }

            $blockLen = strlen($plain);

            // Skip blocks entirely before the window.
            if ($pos + $blockLen <= $start) {
                $pos += $blockLen;

                continue;
            }

            $offset = max(0, $start - $pos);
            $slice = substr($plain, $offset, $left);
            if ($out === null) {
                echo $slice;
                flush();
            } else {
                fwrite($out, $slice);
            }

            $left -= strlen($slice);
            $pos += $blockLen;
        }
    }

    /** Context string binding a key to one object version. */
    public function context(int $bucketId, string $key, ?string $versionId): string
    {
        return $bucketId.':'.$key.':'.($versionId ?: 'null');
    }
}
