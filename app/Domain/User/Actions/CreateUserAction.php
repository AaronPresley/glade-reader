<?php

namespace App\Domain\User\Actions;

use App\Domain\User\Models\User;

class CreateUserAction
{
    public function handle(string $username, string $email, string $password): User
    {
        return User::create([
            'name' => $username,
            'username' => $username,
            'email' => $email,
            'password' => $password,
        ]);
    }
}
