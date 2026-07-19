<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Read-only public demo data for StorageMGR: buckets, objects, access keys,
 *  and policies. Idempotent. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['storage_objects', 'access_keys', 'buckets', 'policies'] as $t) {
            if (DB::getSchemaBuilder()->hasTable($t)) DB::table($t)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $uid = DB::table('users')->where('email', 'demo@scriptgain.com')->value('id')
            ?? DB::table('users')->insertGetId(['name' => 'Demo Admin', 'email' => 'demo@scriptgain.com', 'password' => Hash::make(Str::random(40)), 'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('settings')->updateOrInsert(['key' => 'setup_complete'], ['value' => '1']);

        $policies = [];
        foreach ([
            ['read-only', 'Read-only access to all buckets', ['Statement' => [['Effect' => 'Allow', 'Action' => ['s3:GetObject', 's3:ListBucket'], 'Resource' => '*']]]],
            ['full-access', 'Full read/write access', ['Statement' => [['Effect' => 'Allow', 'Action' => 's3:*', 'Resource' => '*']]]],
            ['uploads-write', 'Write access to the uploads bucket only', ['Statement' => [['Effect' => 'Allow', 'Action' => 's3:PutObject', 'Resource' => 'arn:aws:s3:::uploads/*']]]],
        ] as [$n, $d, $doc]) {
            $policies[] = DB::table('policies')->insertGetId(['user_id' => $uid, 'name' => $n, 'description' => $d, 'document' => json_encode($doc), 'created_at' => now(), 'updated_at' => now()]);
        }

        $bucketDefs = [
            ['app-uploads', 'us-east-1', 'private', true], ['static-assets', 'us-east-1', 'public-read', false],
            ['db-backups', 'us-west-2', 'private', true], ['media-library', 'eu-central-1', 'private', true],
            ['log-archive', 'us-west-2', 'private', false], ['public-downloads', 'us-east-1', 'public-read', false],
        ];
        $exts = [['jpg', 'image/jpeg'], ['png', 'image/png'], ['pdf', 'application/pdf'], ['zip', 'application/zip'], ['mp4', 'video/mp4'], ['json', 'application/json'], ['csv', 'text/csv'], ['gz', 'application/gzip']];
        $dirs = ['uploads', 'exports', '2026/07', 'thumbnails', 'documents', 'backups', 'media'];
        foreach ($bucketDefs as [$name, $region, $access, $ver]) {
            $objCount = random_int(8, 60);
            $sizeTotal = 0;
            $bid = DB::table('buckets')->insertGetId([
                'user_id' => $uid, 'name' => $name, 'region' => $region, 'access' => $access,
                'versioning' => $ver ? 1 : 0, 'quota_bytes' => 1099511627776, 'object_count' => 0, 'size_bytes' => 0,
                'created_at' => now()->subDays(random_int(20, 120)), 'updated_at' => now(),
            ]);
            $rows = [];
            $show = min($objCount, 40);
            for ($i = 0; $i < $show; $i++) {
                [$ext, $ct] = $exts[random_int(0, count($exts) - 1)];
                $sz = random_int(2048, 900000000);
                $sizeTotal += $sz;
                $rows[] = [
                    'bucket_id' => $bid, 'key' => $dirs[random_int(0, count($dirs) - 1)].'/'.Str::random(10).'.'.$ext,
                    'size_bytes' => $sz, 'content_type' => $ct, 'etag' => md5(Str::random(20)),
                    'last_modified' => now()->subDays(random_int(0, 90)), 'created_at' => now(), 'updated_at' => now(),
                ];
            }
            DB::table('storage_objects')->insert($rows);
            DB::table('buckets')->where('id', $bid)->update(['object_count' => $objCount, 'size_bytes' => $sizeTotal * (int) ceil($objCount / max(1, $show))]);
        }

        foreach ([['prod-app-key', 'active'], ['ci-deploy-key', 'active'], ['readonly-analytics', 'active'], ['legacy-key', 'disabled']] as $i => [$n, $st]) {
            DB::table('access_keys')->insert([
                'user_id' => $uid, 'name' => $n, 'access_key_id' => 'AK'.Str::upper(Str::random(18)),
                'secret_key' => Str::random(40), 'status' => $st, 'policy_id' => $policies[$i % count($policies)],
                'last_used_at' => $st === 'active' ? now()->subHours(random_int(1, 200)) : null,
                'created_at' => now()->subDays(random_int(10, 100)), 'updated_at' => now(),
            ]);
        }

        $this->command?->info('Storage demo seeded: '.count($bucketDefs).' buckets, '.count($policies).' policies.');
    }
}
