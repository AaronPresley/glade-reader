<?php

use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('redirects guests to login for authenticated pages', function (): void {
    $this->get('/')
        ->assertRedirect('/login');
});

it('shows login', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('login/index'));
});

it('redirects authenticated users away from login', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/login')
        ->assertRedirect('/');

    $this->actingAs($user)
        ->post('/login', [
            'username' => $user->username,
            'password' => 'password',
        ])
        ->assertRedirect('/');
});

it('logs in with username and password', function (): void {
    User::factory()->create([
        'username' => 'apresley',
        'password' => 'password',
    ]);

    $this->post('/login', [
        'username' => 'apresley',
        'password' => 'password',
    ])
        ->assertRedirect('/');

    $this->assertAuthenticated();
});

it('logs out and redirects home', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect('/');

    $this->assertGuest();
});

it('rejects invalid login credentials', function (): void {
    User::factory()->create([
        'username' => 'apresley',
        'password' => 'password',
    ]);

    $this->post('/login', [
        'username' => 'apresley',
        'password' => 'wrong-password',
    ])
        ->assertSessionHasErrors('username');

    $this->assertGuest();
});
