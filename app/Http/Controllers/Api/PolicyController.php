<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Policy;
use Illuminate\Http\Request;

class PolicyController extends Controller
{
    public function index(Request $request)
    {
        return Policy::visibleTo($request->user())
            ->withCount('accessKeys')
            ->latest()
            ->paginate(50);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['user_id'] = $this->resolveOwner($request);

        return response()->json(Policy::create($data), 201);
    }

    public function show(Policy $policy)
    {
        abort_unless($policy->isVisibleTo(auth()->user()), 403);

        return $policy->loadCount('accessKeys');
    }

    public function update(Request $request, Policy $policy)
    {
        abort_unless($policy->isVisibleTo($request->user()), 403);

        $data = $this->validated($request, updating: true);

        if ($request->user()->isAdmin() && $request->filled('user_id')) {
            $data['user_id'] = $request->validate([
                'user_id' => ['integer', 'exists:users,id'],
            ])['user_id'];
        } else {
            unset($data['user_id']);
        }

        $policy->update($data);

        return $policy;
    }

    public function destroy(Policy $policy)
    {
        abort_unless($policy->isVisibleTo(auth()->user()), 403);

        $policy->delete();

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

    private function validated(Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$req, 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'document' => [$req, 'json'],
        ]);

        // Pretty-print so the stored document matches what the textarea shows back.
        if (isset($data['document'])) {
            $data['document'] = json_encode(json_decode($data['document'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return $data;
    }
}
