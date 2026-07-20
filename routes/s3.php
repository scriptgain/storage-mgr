<?php

use App\Http\Controllers\S3\S3Controller;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| S3 protocol endpoint
|--------------------------------------------------------------------------
|
| Path-style S3: /{bucket} and /{bucket}/{key}. Those patterns would swallow
| the web console's own routes, so the endpoint is isolated:
|
|   STORAGE_S3_DOMAIN set  -> served at the root of that hostname (a true
|                             drop-in endpoint, e.g. s3.example.com)
|   otherwise              -> served under the /s3 prefix on this host, which
|                             SDKs accept via --endpoint-url https://host/s3
|
| Auth is SigV4 over the access keys, never the session, so these routes are
| stateless and CSRF does not apply.
|
*/

Route::middleware('s3.auth')->group(function () {
    Route::get('/', [S3Controller::class, 'listBuckets']);

    Route::put('/{bucket}', [S3Controller::class, 'createBucket'])->where('bucket', '[^/]+');
    Route::get('/{bucket}', [S3Controller::class, 'listObjects'])->where('bucket', '[^/]+');
    Route::match(['head'], '/{bucket}', [S3Controller::class, 'headBucket'])->where('bucket', '[^/]+');
    Route::delete('/{bucket}', [S3Controller::class, 'deleteBucket'])->where('bucket', '[^/]+');

    // {key} is greedy: object keys legitimately contain slashes.
    // POST carries the multipart create/complete operations.
    Route::post('/{bucket}/{key}', [S3Controller::class, 'postObject'])->where('key', '.*');
    Route::put('/{bucket}/{key}', [S3Controller::class, 'putObject'])->where('key', '.*');
    Route::get('/{bucket}/{key}', [S3Controller::class, 'getObject'])->where('key', '.*');
    Route::match(['head'], '/{bucket}/{key}', [S3Controller::class, 'headObject'])->where('key', '.*');
    Route::delete('/{bucket}/{key}', [S3Controller::class, 'deleteObject'])->where('key', '.*');
});
