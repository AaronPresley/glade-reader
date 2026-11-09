<?php

use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

it('requires a browser login to manage tokens', function (): void {
    $this->get('/settings/api-tokens')->assertRedirect('/login');
    $this->post('/settings/api-tokens', ['name' => 'Reader'])->assertRedirect('/login');
    $this->delete('/settings/api-tokens/1')->assertRedirect('/login');
});

it('generates a scoped read submit and regenerate token and only reveals its secret once', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user, 'web')->post('/settings/api-tokens', ['name' => 'My integration', 'abilities' => ['*']])
        ->assertRedirect('/settings/api-tokens');
    $response = $this->get('/settings/api-tokens')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/api-tokens')
            ->has('tokens', 1)->where('tokens.0.name', 'My integration')
            ->where('tokens.0.abilities', ['read', 'sources:create', 'entries:generate'])
            ->missing('tokens.0.token')->missing('tokens.0.tokenable_id')->hasFlash('newToken'));
    $secret = $response->viewData('page')['flash']['newToken'];
    expect(PersonalAccessToken::findToken($secret)->abilities)->toBe(['read', 'sources:create', 'entries:generate']);
    $this->get('/settings/api-tokens')->assertInertia(fn (Assert $page) => $page->missingFlash('newToken'));

    $this->app['auth']->forgetGuards();
    $this->withToken($secret)->getJson('/api/entries')->assertOk();
    $this->actingAs($user, 'web')->delete('/settings/api-tokens/'.$user->tokens()->firstOrFail()->id)
        ->assertRedirect('/settings/api-tokens');
    $this->app['auth']->forgetGuards();
    $this->withToken($secret)->getJson('/api/entries')->assertUnauthorized();
});

it('validates names and keeps token management scoped to the owner', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $ownToken = $user->createToken('Mine', ['read']);
    $otherToken = $other->createToken('Private integration', ['read']);
    $this->actingAs($user, 'web')->get('/settings/api-tokens')
        ->assertInertia(fn (Assert $page) => $page->has('tokens', 1)->where('tokens.0.name', 'Mine')
            ->where('tokens.0.abilities', ['read'])
            ->missing('tokens.0.token')->missingFlash('newToken'));
    $this->post('/settings/api-tokens', ['name' => '  '])->assertSessionHasErrors('name');
    $this->post('/settings/api-tokens', ['name' => str_repeat('a', 256)])->assertSessionHasErrors('name');
    $this->post('/settings/api-tokens', ['name' => 'New permissions'])->assertRedirect('/settings/api-tokens');
    expect($ownToken->accessToken->fresh()->abilities)->toBe(['read']);
    $this->delete('/settings/api-tokens/'.$otherToken->accessToken->id)->assertNotFound();
    $this->delete('/settings/api-tokens/'.$ownToken->accessToken->id)->assertRedirect('/settings/api-tokens');
    expect(PersonalAccessToken::findToken($otherToken->plainTextToken))->not->toBeNull();
});
