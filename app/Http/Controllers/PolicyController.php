<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PolicyController extends Controller
{
    public function index()
    {
        $policies = Policy::visibleTo(auth()->user())->with('owner:id,name')
            ->withCount('accessKeys')->latest()->paginate(25)->withQueryString();

        return view('policies.index', compact('policies'));
    }

    public function create()
    {
        return view('policies.create', [
            'defaultDocument' => Policy::defaultDocument(),
            'owners' => $this->assignableOwners(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $this->resolveOwner($request);
        unset($data['owner_id']);

        $policy = Policy::create($data);
        $this->assignFromRequest($policy, $request);
        AuditLog::record('created', "Policy \"{$policy->name}\" created", $policy);

        return redirect()->route('policies.show', $policy)->with('status', "Policy \"{$policy->name}\" created.");
    }

    public function show(Policy $policy)
    {
        $this->guard($policy);
        $policy->loadCount('accessKeys');

        return view('policies.show', compact('policy'));
    }

    public function edit(Policy $policy)
    {
        $this->guard($policy);

        return view('policies.edit', ['policy' => $policy, 'owners' => $this->assignableOwners()]);
    }

    public function update(Request $request, Policy $policy)
    {
        $this->guard($policy);
        $data = $this->validated($request);
        if (auth()->user()->isAdmin()) {
            $data['user_id'] = $data['owner_id'] ?? null;
        }
        unset($data['owner_id']);

        $policy->update($data);
        $this->assignFromRequest($policy, $request);
        AuditLog::record('updated', "Policy \"{$policy->name}\" updated", $policy);

        return redirect()->route('policies.show', $policy)->with('status', 'Policy updated.');
    }

    public function destroy(Policy $policy)
    {
        $this->guard($policy);
        $name = $policy->name;
        $policy->delete();
        AuditLog::record('deleted', "Policy \"{$name}\" deleted");

        return redirect()->route('policies.index')->with('status', "Policy \"{$name}\" deleted.");
    }

    /**
     * Bulk-delete selected policies. Only the submitted ids are touched, and
     * only policies the current user is allowed to see.
     */
    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $ids = Policy::visibleTo(auth()->user())->whereIn('id', $data['ids'])->pluck('id');

        if ($ids->isEmpty()) {
            return back()->with('warning', 'No matching policies were selected.');
        }

        $count = Policy::whereIn('id', $ids->all())->delete();

        AuditLog::record('deleted', "Bulk deleted {$count} polic".($count === 1 ? 'y' : 'ies').'.');

        return back()->with('status', $count.' polic'.($count === 1 ? 'y' : 'ies').' deleted.');
    }

    private function guard(Policy $policy): void
    {
        abort_unless($policy->isVisibleTo(auth()->user()), 403);
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

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'document' => ['required', 'json'],
            'owner_id' => ['nullable', Rule::exists('users', 'id')],
        ]);
        // Pretty-print so the stored document matches what the textarea shows back.
        $data['document'] = json_encode(json_decode($data['document'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $data;
    }
}
