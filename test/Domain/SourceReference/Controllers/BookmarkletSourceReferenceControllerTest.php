<?php

use App\Domain\SourceReference\Jobs\ProcessSourceReferenceJob;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('installs a personal bookmarklet and saves only after its authenticated post', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $signedUrl = URL::signedRoute('source-references.bookmarklet', ['user' => $user->id]);
    $originalUrl = 'https://example.com/articles/a?search=one%20two&next=%2Fother#section-2';
    $captureUrl = $signedUrl.'&url='.rawurlencode($originalUrl);

    $this->actingAs($user)->get('/source-references/create')
        ->assertInertia(fn (Assert $page) => $page
            ->component('source-references/create')
            ->where('bookmarkletUrl', $signedUrl));

    $this->get($captureUrl)
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertInertia(fn (Assert $page) => $page
            ->component('source-references/bookmarklet')
            ->where('captureUrl', $captureUrl)
            ->where('errorMessage', null));

    $this->assertDatabaseCount('source_references', 0);
    Bus::assertNothingDispatched();

    $this->post($captureUrl, [], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', $originalUrl);

    $this->assertDatabaseCount('source_references', 1);
    $this->assertDatabaseHas('source_references', [
        'user_id' => $user->id,
        'url' => $originalUrl,
        'status' => 'NEW',
    ]);
    Bus::assertDispatched(ProcessSourceReferenceJob::class);
});

it('resumes the bookmarklet after login without losing the original page', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $originalUrl = 'https://example.com/guest?x=1&y=2#read';
    $captureUrl = URL::signedRoute('source-references.bookmarklet', ['user' => $user->id])
        .'&url='.rawurlencode($originalUrl);

    $this->get($captureUrl)->assertRedirect('/login');
    $this->get('/login')->assertOk();
    $this->post('/login', ['username' => $user->username, 'password' => 'incorrect'])
        ->assertSessionHasErrors('username');
    $this->assertDatabaseCount('source_references', 0);

    $this->post('/login', ['username' => $user->username, 'password' => 'password'])
        ->assertRedirect($captureUrl);
    $this->get($captureUrl)->assertOk();
    $this->assertDatabaseCount('source_references', 0);

    $this->post($captureUrl, [], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', $originalUrl);
    $this->assertDatabaseHas('source_references', ['user_id' => $user->id, 'url' => $originalUrl]);
    Bus::assertDispatched(ProcessSourceReferenceJob::class);
});

it('accepts an https signed bookmarklet through the http reverse proxy', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    URL::forceScheme('https');
    $originalUrl = 'https://hunch.net/?p=13764006';
    $captureUrl = URL::signedRoute('source-references.bookmarklet', ['user' => $user->id])
        .'&url='.rawurlencode($originalUrl);
    $proxyUrl = preg_replace('/^https:/', 'http:', $captureUrl);

    $this->actingAs($user)->withHeaders(['X-Forwarded-Proto' => 'https']);
    $this->get($proxyUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('captureUrl', $captureUrl));
    $this->assertDatabaseCount('source_references', 0);

    $this->post($proxyUrl, [], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', $originalUrl);
    $this->assertDatabaseHas('source_references', ['user_id' => $user->id, 'url' => $originalUrl]);
    Bus::assertDispatched(ProcessSourceReferenceJob::class);
});

it('requires both the signed bookmarklet and its logged in owner on get and post', function (): void {
    Bus::fake();
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $signedUrl = URL::signedRoute('source-references.bookmarklet', ['user' => $owner->id]);
    $captureUrl = $signedUrl.'&url='.rawurlencode('https://example.com/security');

    $this->post($captureUrl)->assertRedirect('/login');
    $this->actingAs($otherUser);
    $this->get($captureUrl)->assertForbidden();
    $this->post($captureUrl)->assertForbidden();

    $this->actingAs($owner);
    $unsignedUrl = route('source-references.bookmarklet', ['user' => $owner->id, 'url' => 'https://example.com']);
    $tamperedOwner = str_replace('/bookmarklet/'.$owner->id, '/bookmarklet/'.$otherUser->id, $captureUrl);
    $tamperedSignature = str_replace('signature=', 'signature=invalid', $captureUrl);
    foreach ([$unsignedUrl, $tamperedSignature, $tamperedOwner] as $invalidUrl) {
        $this->get($invalidUrl)->assertForbidden();
        $this->post($invalidUrl)->assertForbidden();
    }

    $this->assertDatabaseCount('source_references', 0);
    Bus::assertNothingDispatched();
});

it('shows invalid captured urls without saving or redirecting away', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $signedUrl = URL::signedRoute('source-references.bookmarklet', ['user' => $user->id]);
    $this->actingAs($user);

    foreach (['', 'javascript:alert(1)', 'file:///etc/passwd', 'https://example.com/'.str_repeat('a', 2048)] as $url) {
        $captureUrl = $signedUrl.'&url='.rawurlencode($url);
        foreach (['get', 'post'] as $method) {
            $this->{$method}($captureUrl)
                ->assertStatus(422)
                ->assertInertia(fn (Assert $page) => $page
                    ->component('source-references/bookmarklet')
                    ->where('captureUrl', null)
                    ->where('errorMessage', fn ($message) => is_string($message) && $message !== ''));
        }
    }

    $this->assertDatabaseCount('source_references', 0);
    Bus::assertNothingDispatched();
});
