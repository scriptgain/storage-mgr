<?php

namespace App\Http\Controllers;

use App\Models\AccessKey;
use App\Models\AuditLog;
use App\Models\Bucket;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaintenanceController extends Controller
{
    /** Ordered day-of-week tokens matching Carbon's lowercase `D` format. */
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** Defaults for every Maintenance setting. Keys are Setting table keys. */
    public static function defaults(): array
    {
        return [
            'auto_maintenance' => '1',
            'maintenance_window_enabled' => '0',
            'maintenance_window_start' => '02:00',
            'maintenance_window_end' => '05:00',
            'maintenance_days' => implode(',', self::DAYS),
            // Tasks.
            'recalc_bucket_stats' => '1',
            'disable_stale_keys' => '0',
            'stale_key_days' => '90',
            'audit_log_days' => '180',
        ];
    }

    public static function values(): array
    {
        $map = Setting::map();
        $v = [];
        foreach (static::defaults() as $key => $default) {
            $v[$key] = $map[$key] ?? $default;
        }

        return $v;
    }

    /** Whether the scheduled sweep may run right now, honoring the window. */
    public static function allowedNow(?array $s = null, ?\DateTimeInterface $now = null): bool
    {
        $s ??= static::values();
        if (($s['auto_maintenance'] ?? '1') !== '1') {
            return false;
        }
        if (($s['maintenance_window_enabled'] ?? '0') !== '1') {
            return true;
        }

        $now = $now ? \Illuminate\Support\Carbon::instance($now) : now();

        $days = array_filter(explode(',', $s['maintenance_days'] ?? ''));
        if ($days && ! in_array(strtolower($now->format('D')), $days, true)) {
            return false;
        }

        $start = $s['maintenance_window_start'] ?? '00:00';
        $end = $s['maintenance_window_end'] ?? '23:59';
        $cur = $now->format('H:i');

        return $start <= $end
            ? ($cur >= $start && $cur <= $end)
            : ($cur >= $start || $cur <= $end);
    }

    /** Run the housekeeping sweep. Returns per-task counts. */
    public static function runSweep(?array $s = null): array
    {
        $s ??= static::values();
        $counts = ['buckets_recalced' => 0, 'keys_disabled' => 0, 'audit_pruned' => 0];

        // 1. Recompute object_count/size_bytes from the objects table (fixes drift).
        if (($s['recalc_bucket_stats'] ?? '1') === '1') {
            Bucket::query()->get()->each(function (Bucket $b) use (&$counts) {
                $b->refreshStats();
                $counts['buckets_recalced']++;
            });
        }

        // 2. Disable access keys that have gone unused past the stale window.
        //    Only keys that have been used at least once are touched, so fresh
        //    never-used keys are never disabled by surprise.
        if (($s['disable_stale_keys'] ?? '0') === '1') {
            $days = max(1, (int) ($s['stale_key_days'] ?? 90));
            $counts['keys_disabled'] = AccessKey::where('status', 'active')
                ->whereNotNull('last_used_at')
                ->where('last_used_at', '<', now()->subDays($days))
                ->update(['status' => 'disabled']);
        }

        // 3. Prune old audit rows.
        $auditDays = (int) ($s['audit_log_days'] ?? 180);
        if ($auditDays > 0) {
            $counts['audit_pruned'] = AuditLog::where('created_at', '<', now()->subDays($auditDays))->delete();
        }

        return $counts;
    }

    public function edit()
    {
        $v = static::values();

        return view('settings.maintenance', [
            'v' => $v,
            'days' => self::DAYS,
            'selectedDays' => array_filter(explode(',', $v['maintenance_days'])),
            'allowedNow' => static::allowedNow($v),
            'now' => now(),
            'stats' => [
                'Buckets' => Bucket::count(),
                'Active Access Keys' => AccessKey::where('status', 'active')->count(),
                'Stale Active Keys (past due)' => AccessKey::where('status', 'active')
                    ->whereNotNull('last_used_at')
                    ->where('last_used_at', '<', now()->subDays((int) $v['stale_key_days']))->count(),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'maintenance_window_start' => ['required', 'date_format:H:i'],
            'maintenance_window_end' => ['required', 'date_format:H:i'],
            'maintenance_days' => ['nullable', 'array'],
            'maintenance_days.*' => [Rule::in(self::DAYS)],
            'stale_key_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'audit_log_days' => ['required', 'integer', 'min:0', 'max:3650'],
        ]);

        foreach (['auto_maintenance', 'maintenance_window_enabled', 'recalc_bucket_stats', 'disable_stale_keys'] as $t) {
            Setting::put($t, $request->boolean($t) ? '1' : '0');
        }

        Setting::put('maintenance_window_start', $data['maintenance_window_start']);
        Setting::put('maintenance_window_end', $data['maintenance_window_end']);
        Setting::put('maintenance_days', implode(',', $data['maintenance_days'] ?? []));
        Setting::put('stale_key_days', (string) $data['stale_key_days']);
        Setting::put('audit_log_days', (string) $data['audit_log_days']);

        AuditLog::record('updated', 'Maintenance settings updated');

        return back()->with('status', 'Maintenance settings saved.');
    }

    public function runNow()
    {
        $c = static::runSweep();
        AuditLog::record('maintenance', "Manual maintenance: {$c['buckets_recalced']} buckets recalculated, {$c['keys_disabled']} keys disabled, {$c['audit_pruned']} audit rows pruned");

        return back()->with('status', "Maintenance ran: {$c['buckets_recalced']} bucket(s) recalculated, {$c['keys_disabled']} stale key(s) disabled, {$c['audit_pruned']} audit row(s) pruned.");
    }
}
