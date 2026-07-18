<?php

namespace App\Http\Controllers;

use App\Models\AccessKey;
use App\Models\AuditLog;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccessKeyController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $accessKeys = AccessKey::visibleTo($user)->with('policy', 'owner:id,name')->latest()->paginate(25)->withQueryString();

        $stats = [
            'total' => AccessKey::visibleTo($user)->count(),
            'active' => AccessKey::visibleTo($user)->where('status', 'active')->count(),
        ];

        return view('access-keys.index', compact('accessKeys', 'stats'));
    }

    public function create()
    {
        return view('access-keys.create', [
            'policies' => Policy::visibleTo(auth()->user())->orderBy('name')->get(),
            'owners' => $this->assignableOwners(),
        ]);
    }

    /** Stores + shows the plaintext secret once (never retrievable again). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'policy_id' => ['nullable', 'integer', 'exists:policies,id'],
            'owner_id' => ['nullable', Rule::exists('users', 'id')],
        ]);
        $this->assertPolicyAllowed($request, $data['policy_id'] ?? null);

        $secret = AccessKey::generateSecretKey();
        $accessKey = AccessKey::create([
            'user_id' => $this->resolveOwner($request),
            'name' => $data['name'],
            'access_key_id' => AccessKey::generateAccessKeyId(),
            'secret_key' => $secret,
            'status' => 'active',
            'policy_id' => $data['policy_id'] ?? null,
        ]);
        $this->assignFromRequest($accessKey, $request);
        AuditLog::record('created', "Access key \"{$accessKey->name}\" created", $accessKey);

        return redirect()->route('access-keys.index')
            ->with('status', "Access key \"{$accessKey->name}\" created.")
            ->with('reveal_secret', $secret)
            ->with('reveal_id', $accessKey->id);
    }

    /** Route wildcard is {access_key} (Laravel dashes-to-underscore for resource params); name must match for implicit binding. */
    public function setStatus(Request $request, AccessKey $access_key)
    {
        $this->guard($access_key);
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(AccessKey::STATUSES))]]);
        $access_key->update(['status' => $data['status']]);
        AuditLog::record('updated', "Access key \"{$access_key->name}\" set to {$data['status']}", $access_key);

        return back()->with('status', "Access key {$access_key->statusLabel()}.");
    }

    public function destroy(AccessKey $access_key)
    {
        $this->guard($access_key);
        $name = $access_key->name;
        $access_key->delete();
        AuditLog::record('deleted', "Access key \"{$name}\" deleted");

        return redirect()->route('access-keys.index')->with('status', "Access key \"{$name}\" deleted.");
    }

    /**
     * Bulk-delete selected access keys. Only the submitted ids are touched, and
     * only access keys the current user is allowed to see.
     */
    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $ids = AccessKey::visibleTo(auth()->user())->whereIn('id', $data['ids'])->pluck('id');

        if ($ids->isEmpty()) {
            return back()->with('warning', 'No matching access keys were selected.');
        }

        $count = AccessKey::whereIn('id', $ids->all())->delete();

        AuditLog::record('deleted', "Bulk deleted {$count} access key".($count === 1 ? '' : 's').'.');

        return back()->with('status', $count.' access key'.($count === 1 ? '' : 's').' deleted.');
    }

    private function guard(AccessKey $accessKey): void
    {
        abort_unless($accessKey->isVisibleTo(auth()->user()), 403);
    }

    /** A non-admin may only attach one of their own policies. */
    private function assertPolicyAllowed(Request $request, ?int $policyId): void
    {
        $user = $request->user();
        if ($user->isAdmin() || ! $policyId) {
            return;
        }
        $policy = Policy::find($policyId);
        abort_unless($policy && $policy->isVisibleTo($user), 403, 'You do not own that policy.');
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
}
