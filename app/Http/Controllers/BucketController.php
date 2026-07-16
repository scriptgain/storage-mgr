<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Bucket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BucketController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $buckets = Bucket::visibleTo($user)->with('owner:id,name')->latest()->paginate(25)->withQueryString();

        $stats = [
            'total' => Bucket::visibleTo($user)->count(),
            'public' => Bucket::visibleTo($user)->where('access', 'public')->count(),
            'objects' => (int) Bucket::visibleTo($user)->sum('object_count'),
        ];

        return view('buckets.index', compact('buckets', 'stats'));
    }

    public function create()
    {
        return view('buckets.create', ['owners' => $this->assignableOwners()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $this->resolveOwner($request);
        unset($data['owner_id']);

        $bucket = Bucket::create($data);
        $this->assignFromRequest($bucket, $request);
        AuditLog::record('created', "Bucket \"{$bucket->name}\" created", $bucket);

        return redirect()->route('buckets.show', $bucket)->with('status', "Bucket \"{$bucket->name}\" created.");
    }

    public function show(Bucket $bucket)
    {
        $this->guard($bucket);
        $objects = $bucket->objects()->orderBy('key')->paginate(50)->withQueryString();

        return view('buckets.show', compact('bucket', 'objects'));
    }

    public function edit(Bucket $bucket)
    {
        $this->guard($bucket);

        return view('buckets.edit', ['bucket' => $bucket, 'owners' => $this->assignableOwners()]);
    }

    public function update(Request $request, Bucket $bucket)
    {
        $this->guard($bucket);
        $data = $this->validated($request, $bucket);
        if (auth()->user()->isAdmin()) {
            $data['user_id'] = $data['owner_id'] ?? null;
        }
        unset($data['owner_id']);

        $bucket->update($data);
        $this->assignFromRequest($bucket, $request);
        AuditLog::record('updated', "Bucket \"{$bucket->name}\" updated", $bucket);

        return redirect()->route('buckets.show', $bucket)->with('status', 'Bucket updated.');
    }

    public function destroy(Bucket $bucket)
    {
        $this->guard($bucket);
        $name = $bucket->name;
        $bucket->delete();
        AuditLog::record('deleted', "Bucket \"{$name}\" deleted");

        return redirect()->route('buckets.index')->with('status', "Bucket \"{$name}\" deleted.");
    }

    private function guard(Bucket $bucket): void
    {
        abort_unless($bucket->isVisibleTo(auth()->user()), 403);
    }

    private function resolveOwner(Request $request): int
    {
        $user = $request->user();

        return $user->isAdmin() ? (int) ($request->input('owner_id') ?: $user->id) : $user->id;
    }

    private function assignableOwners()
    {
        return auth()->user()->isAdmin()
            ? User::orderBy('name')->get(['id', 'name', 'email'])
            : collect();
    }

    /** Sync extra assignees from the request. Admins only; others leave the set untouched. */
    private function assignFromRequest($model, Request $request): void
    {
        if (! auth()->user()->isAdmin() || ! method_exists($model, 'syncAssignees')) {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('assignees', [])))));
        $model->syncAssignees($ids);
    }

    private function validated(Request $request, ?Bucket $bucket = null): array
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:63', 'regex:/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/',
                Rule::unique('buckets', 'name')->ignore($bucket?->id),
            ],
            'region' => ['required', 'string', 'max:60'],
            'access' => ['required', Rule::in(array_keys(Bucket::ACCESS_LEVELS))],
            'quota_bytes' => ['nullable', 'integer', 'min:0'],
            'owner_id' => ['nullable', Rule::exists('users', 'id')],
        ], [
            'name.regex' => 'Bucket names must be lowercase letters, numbers, dots, and hyphens only.',
        ]);
        $data['versioning'] = $request->boolean('versioning');

        return $data;
    }
}
