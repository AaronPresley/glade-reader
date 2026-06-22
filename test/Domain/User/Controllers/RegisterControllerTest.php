<?php

use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shows register before a user exists and returns not found after one exists', function (): void {
    $this->get('/register')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('register/index'));

    User::factory()->create();

    $this->get('/register')->assertNotFound();
});

it('redirects authenticated users away from register', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/register')
        ->assertRedirect('/');

    $this->actingAs($user)
        ->post('/register', [
            'username' => 'second',
            'email' => 'second@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertRedirect('/');
});

it('registers the first user', function (): void {
    $this->post('/register', [
        'username' => 'apresley',
        'email' => 'apresley@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])
        ->assertRedirect('/');

    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', [
        'name' => 'apresley',
        'username' => 'apresley',
        'email' => 'apresley@example.com',
    ]);
});

it('returns not found when registering after a user exists', function (): void {
    User::factory()->create();

    $this->post('/register', [
        'username' => 'second',
        'email' => 'second@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])
        ->assertNotFound();
});
