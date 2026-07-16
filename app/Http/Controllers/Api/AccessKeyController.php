<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessKey;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccessKeyController extends Controller
{
    public function index(Request $request)
    {
        return AccessKey::visibleTo($request->user())
            ->with('policy:id,name')
            ->latest()
            ->paginate(50);
    }

    /** Returns the plaintext secret ONCE; it is never retrievable again. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'policy_id' => ['nullable', 'exists:policies,id'],
        ]);

        $accessKey = AccessKey::create([
            'name' => $data['name'],
            'access_key_id' => AccessKey::generateAccessKeyId(),
            'secret_key' => $secret = AccessKey::generateSecretKey(),
            'status' => 'active',
            'policy_id' => $data['policy_id'] ?? null,
            'user_id' => $this->resolveOwner($request),
        ]);

        return response()->json([
            'access_key' => $accessKey,
            'secret_key' => $secret,
        ], 201);
    }

    public function show(AccessKey $accessKey)
    {
        abort_unless($accessKey->isVisibleTo(auth()->user()), 403);

        return $accessKey->load('policy:id,name');
    }

    public function destroy(AccessKey $accessKey)
    {
        abort_unless($accessKey->isVisibleTo(auth()->user()), 403);

        $accessKey->delete();

        return response()->noContent();
    }

    public function setStatus(Request $request, AccessKey $accessKey)
    {
        abort_unless($accessKey->isVisibleTo($request->user()), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(AccessKey::STATUSES))],
        ]);

        $accessKey->update(['status' => $data['status']]);

        return $accessKey->fresh();
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
}
