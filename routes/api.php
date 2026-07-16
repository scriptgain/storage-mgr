<?php

use App\Http\Controllers\Api\AccessKeyController;
use App\Http\Controllers\Api\ApiTokenController;
use App\Http\Controllers\Api\BucketController;
use App\Http\Controllers\Api\PolicyController;
use App\Http\Controllers\Api\StorageObjectController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Authenticated admin REST API. Bearer token (api_tokens) auth; results are
// scoped to the token owner (admins see everything). Base: /api/v1
Route::prefix('v1')->name('api.')->middleware('api.token')->group(function () {
    Route::get('me', fn (Request $r) => $r->user()->only(['id', 'name', 'email', 'role']));

    // Storage (owner-scoped; objects inherit their bucket's owner).
    Route::apiResource('buckets', BucketController::class);
    Route::apiResource('objects', StorageObjectController::class)->only(['index', 'show', 'store', 'destroy'])->parameters(['objects' => 'storageObject']);
    Route::apiResource('access-keys', AccessKeyController::class)->only(['index', 'store', 'show', 'destroy'])->parameters(['access-keys' => 'accessKey']);
    Route::post('access-keys/{accessKey}/status', [AccessKeyController::class, 'setStatus'])->name('access-keys.status');
    Route::apiResource('policies', PolicyController::class);

    // Administration.
    Route::apiResource('users', UserController::class);
    Route::apiResource('api-tokens', ApiTokenController::class)->only(['index', 'store', 'destroy'])->parameters(['api-tokens' => 'apiToken']);
});
