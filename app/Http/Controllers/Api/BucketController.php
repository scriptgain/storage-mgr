<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bucket;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BucketController extends Controller
{
    public function index(Request $request)
    {
        return Bucket::visibleTo($request->user())
            ->withCount('objects')
            ->latest()
            ->paginate(50);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $this->resolveOwner($request);

        return response()->json(Bucket::create($data), 201);
    }

    public function show(Bucket $bucket)
    {
        abort_unless($bucket->isVisibleTo(auth()->user()), 403);

        return $bucket->load('objects');
    }

    public function update(Request $request, Bucket $bucket)
    {
        abort_unless($bucket->isVisibleTo($request->user()), 403);

        $data = $this->validated($request, $bucket, updating: true);

        if ($request->user()->isAdmin() && $request->filled('user_id')) {
            $data['user_id'] = $request->validate([
                'user_id' => ['integer', 'exists:users,id'],
            ])['user_id'];
        } else {
            unset($data['user_id']);
        }

        $bucket->update($data);

        return $bucket;
    }

    public function destroy(Bucket $bucket)
    {
        abort_unless($bucket->isVisibleTo(auth()->user()), 403);

        $bucket->delete();

        return response()->noContent();
    }

    /** Admins may assign an explicit owner; everyone else owns what they create. */
    private function resolveOwner(Request $request): int
    {
        if ($request->user()->isAdmin() && $request->filled('user_id')) {
            return (int) $request->validate([
                'user_id' => ['integer', 'exists:users,id'],
            ])['user_id'];
        }

        return $request->user()->id;
    }

    private function validated(Request $request, ?Bucket $bucket = null, bool $updating = false): array
    {
        $req = $updating ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [
                $req, 'string', 'max:63', 'regex:/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/',
                Rule::unique('buckets', 'name')->ignore($bucket?->id),
            ],
            'region' => [$req, 'string', 'max:60'],
            'access' => [$req, Rule::in(array_keys(Bucket::ACCESS_LEVELS))],
            'quota_bytes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'versioning' => ['sometimes', 'boolean'],
        ], [
            'name.regex' => 'Bucket names must be lowercase letters, numbers, dots, and hyphens only.',
        ]);

        if ($request->has('versioning')) {
            $data['versioning'] = $request->boolean('versioning');
        }

        return $data;
    }
}
