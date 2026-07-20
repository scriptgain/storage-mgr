<?php

use App\Http\Controllers\S3\S3Controller;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| S3 virtual-host addressing
|--------------------------------------------------------------------------
|
| bucket.s3.example.com/key — the form SDKs use by default. The bucket comes
| from the domain parameter rather than the path, so these are the same
| controller actions with the path segment removed. Laravel passes domain
| parameters before route parameters, so {bucket} still arrives first.
|
| Using a domain parameter (rather than rewriting the request) keeps the URI
| and Host exactly as the client sent them, which is what SigV4 signs.
|
*/

Route::middleware('s3.auth')->group(function () {
    Route::get('/', [S3Controller::class, 'listObjects']);
    Route::put('/', [S3Controller::class, 'createBucket']);
    Route::post('/', [S3Controller::class, 'deleteObjects']);
    Route::match(['head'], '/', [S3Controller::class, 'headBucket']);
    Route::delete('/', [S3Controller::class, 'deleteBucket']);

    Route::post('/{key}', [S3Controller::class, 'postObject'])->where('key', '.*');
    Route::put('/{key}', [S3Controller::class, 'putObject'])->where('key', '.*');
    Route::get('/{key}', [S3Controller::class, 'getObject'])->where('key', '.*');
    Route::match(['head'], '/{key}', [S3Controller::class, 'headObject'])->where('key', '.*');
    Route::delete('/{key}', [S3Controller::class, 'deleteObject'])->where('key', '.*');
});
