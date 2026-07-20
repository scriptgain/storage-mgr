<?php

namespace App\Services\S3;

use App\Models\Bucket;
use App\Models\MultipartUpload;
use App\Models\StorageObject;
use App\Services\ObjectStorage;
use Illuminate\Support\Carbon;

/**
 * Applies bucket lifecycle rules: expiring current objects, reaping superseded
 * versions, and abandoning stale multipart uploads.
 *
 * Object Lock always wins. A retention period or legal hold exists precisely to
 * survive routine cleanup, so a locked version is skipped rather than expired —
 * otherwise a lifecycle rule would quietly become a way around WORM.
 */
class LifecycleEngine
{
    public function __construct(private readonly ObjectStorage $storage) {}

    /** Parse an S3 LifecycleConfiguration document into storable rules. */
    public static function parse(string $xml): array
    {
        $rules = [];
        if (! preg_match_all('/<Rule>(.*?)<\/Rule>/s', $xml, $blocks)) {
            return $rules;
        }

        foreach ($blocks[1] as $block) {
            $get = function (string $tag) use ($block) {
                return preg_match('/<'.$tag.'>([^<]*)<\/'.$tag.'>/i', $block, $m) ? trim($m[1]) : null;
            };

            $rules[] = array_filter([
                'id' => $get('ID') ?: 'rule-'.(count($rules) + 1),
                'status' => strtolower((string) $get('Status')) === 'enabled' ? 'enabled' : 'disabled',
                'prefix' => $get('Prefix') ?? '',
                'expiration_days' => ($d = $get('Days')) !== null ? (int) $d : null,
                'expiration_date' => $get('Date'),
                'noncurrent_days' => ($n = $get('NoncurrentDays')) !== null ? (int) $n : null,
                'abort_multipart_days' => ($a = $get('DaysAfterInitiation')) !== null ? (int) $a : null,
                'expired_delete_marker' => str_contains($block, '<ExpiredObjectDeleteMarker>true</ExpiredObjectDeleteMarker>'),
            ], fn ($v) => $v !== null);
        }

        return $rules;
    }

    /** Render stored rules back as S3 XML. */
    public static function toXml(array $rules): string
    {
        $out = '';
        foreach ($rules as $r) {
            $out .= '<Rule><ID>'.S3Xml::esc((string) ($r['id'] ?? '')).'</ID>'
                .'<Status>'.(($r['status'] ?? 'disabled') === 'enabled' ? 'Enabled' : 'Disabled').'</Status>'
                .'<Filter><Prefix>'.S3Xml::esc((string) ($r['prefix'] ?? '')).'</Prefix></Filter>';

            if (isset($r['expiration_days'])) {
                $out .= '<Expiration><Days>'.(int) $r['expiration_days'].'</Days></Expiration>';
            } elseif (! empty($r['expiration_date'])) {
                $out .= '<Expiration><Date>'.S3Xml::esc($r['expiration_date']).'</Date></Expiration>';
            }
            if (isset($r['noncurrent_days'])) {
                $out .= '<NoncurrentVersionExpiration><NoncurrentDays>'.(int) $r['noncurrent_days'].'</NoncurrentDays></NoncurrentVersionExpiration>';
            }
            if (isset($r['abort_multipart_days'])) {
                $out .= '<AbortIncompleteMultipartUpload><DaysAfterInitiation>'.(int) $r['abort_multipart_days'].'</DaysAfterInitiation></AbortIncompleteMultipartUpload>';
            }
            $out .= '</Rule>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<LifecycleConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'.$out.'</LifecycleConfiguration>';
    }

    /**
     * Apply every bucket's rules.
     *
     * @return array{expired:int,versions:int,uploads:int,locked_skipped:int}
     */
    public function run(): array
    {
        $stats = ['expired' => 0, 'versions' => 0, 'uploads' => 0, 'locked_skipped' => 0];

        foreach (Bucket::whereNotNull('lifecycle')->get() as $bucket) {
            foreach ((array) $bucket->lifecycle as $rule) {
                if (($rule['status'] ?? '') !== 'enabled') {
                    continue;
                }
                $this->applyRule($bucket, $rule, $stats);
            }
            $bucket->refreshStats();
        }

        return $stats;
    }

    private function applyRule(Bucket $bucket, array $rule, array &$stats): void
    {
        $prefix = (string) ($rule['prefix'] ?? '');
        $scoped = fn ($q) => $prefix === ''
            ? $q
            : $q->where('key', 'like', str_replace(['%', '_'], ['\%', '\_'], $prefix).'%');

        // 1. Expire current objects past their age.
        $cutoff = $this->cutoff($rule['expiration_days'] ?? null, $rule['expiration_date'] ?? null);
        if ($cutoff) {
            $q = $scoped($bucket->objects()->current())->where('last_modified', '<', $cutoff);
            foreach ($q->get() as $object) {
                if ($object->isLocked()) {
                    $stats['locked_skipped']++;

                    continue;
                }
                $this->expire($bucket, $object);
                $stats['expired']++;
            }
        }

        // 2. Reap superseded versions.
        if (isset($rule['noncurrent_days'])) {
            $cut = Carbon::now()->subDays((int) $rule['noncurrent_days']);
            $q = $scoped($bucket->objects()->where('is_latest', false))->where('updated_at', '<', $cut);
            foreach ($q->get() as $version) {
                if ($version->isLocked()) {
                    $stats['locked_skipped']++;

                    continue;
                }
                $this->storage->delete($version);
                $version->delete();
                $stats['versions']++;
            }
        }

        // 3. Abandon stale multipart uploads, whose parts occupy disk invisibly.
        if (isset($rule['abort_multipart_days'])) {
            $cut = Carbon::now()->subDays((int) $rule['abort_multipart_days']);
            foreach (MultipartUpload::where('bucket_id', $bucket->id)->where('created_at', '<', $cut)->get() as $upload) {
                $this->storage->discardMultipart($upload->upload_id);
                $upload->delete();
                $stats['uploads']++;
            }
        }
    }

    /** Expiring a versioned object hides it behind a delete marker, as S3 does. */
    private function expire(Bucket $bucket, StorageObject $object): void
    {
        if ($bucket->versioning) {
            $bucket->objects()->where('key', $object->key)->update(['is_latest' => false]);
            $bucket->objects()->create([
                'key' => $object->key,
                'version_id' => str_pad((string) (int) (microtime(true) * 1000), 14, '0', STR_PAD_LEFT).bin2hex(random_bytes(6)),
                'is_latest' => true,
                'is_delete_marker' => true,
                'size_bytes' => 0,
                'last_modified' => now(),
            ]);

            return;
        }

        $this->storage->delete($object);
        $object->delete();
    }

    private function cutoff(?int $days, ?string $date): ?Carbon
    {
        if ($days !== null) {
            return Carbon::now()->subDays($days);
        }
        if ($date && strtotime($date)) {
            // A date rule only bites once that date has passed.
            return Carbon::parse($date)->isPast() ? Carbon::now() : null;
        }

        return null;
    }
}
