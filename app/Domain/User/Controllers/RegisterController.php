<?php

namespace App\Domain\User\Controllers;

use App\Domain\User\Actions\CreateUserAction;
use App\Domain\User\Models\User;
use App\Domain\User\Requests\RegisterRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class RegisterController extends Controller
{
    public function create(): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect('/');
        }

        abort_if(User::query()->exists(), 404);

        return Inertia::render('register/index');
    }

    public function store(RegisterRequest $request, CreateUserAction $createUser): RedirectResponse
    {
        if (Auth::check()) {
            return redirect('/');
        }

        abort_if(User::query()->exists(), 404);

        $validated = $request->validated();

        $user = $createUser->handle(
            username: $validated['username'],
            email: $validated['email'],
            password: $validated['password'],
        );

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/');
    }
}
