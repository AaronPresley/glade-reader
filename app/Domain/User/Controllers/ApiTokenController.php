<?php

namespace App\Domain\User\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiTokenController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('settings/api-tokens', [
            'tokens' => $request->user()->tokens()->latest('id')->get()->map(fn ($token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'created_at' => $token->created_at?->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
            ]),
            'apiUrl' => url('/api'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $token = $request->user()->createToken($validated['name'], ['read']);

        Inertia::flash('newToken', $token->plainTextToken);

        return to_route('settings.api-tokens.index');
    }

    public function destroy(Request $request, int $token): RedirectResponse
    {
        $request->user()->tokens()->findOrFail($token)->delete();

        return to_route('settings.api-tokens.index');
    }
}
