<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Object storage root
    |--------------------------------------------------------------------------
    |
    | Where object bytes are written. Each bucket gets its own directory under
    | this root, keyed by bucket id so renaming a bucket never moves data.
    | Point this at a mounted volume in production — it grows without bound.
    |
    */

    'root' => env('STORAGE_ROOT', storage_path('app/objects')),

    /*
    | Largest single upload accepted, in kilobytes. Defaults to 100 GB so the
    | app itself is never the thing that refuses a large file.
    |
    | Note this is only the application's own limit. A single request that big
    | must also get past nginx (client_max_body_size) and, for browser form
    | uploads, PHP (upload_max_filesize / post_max_size). S3 clients avoid all
    | of that by splitting large files into multipart parts, so each request
    | stays small no matter how big the object is.
    */
    'max_upload_kb' => (int) env('STORAGE_MAX_UPLOAD_KB', 100 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | S3 protocol endpoint
    |--------------------------------------------------------------------------
    |
    | Set a dedicated hostname to serve S3 at its root, which is what SDKs
    | expect from a drop-in endpoint:
    |
    |     STORAGE_S3_DOMAIN=s3.example.com
    |
    | Leave it empty and S3 is served under the prefix below on the console's
    | own host, reachable with --endpoint-url https://console-host/s3
    |
    */

    's3_domain' => env('STORAGE_S3_DOMAIN'),

    's3_prefix' => env('STORAGE_S3_PREFIX', 's3'),

];
