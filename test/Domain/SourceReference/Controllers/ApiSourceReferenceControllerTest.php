<?php

use App\Domain\SourceReference\Enums\SourceReferenceStatus;
use App\Domain\SourceReference\Jobs\ProcessSourceReferenceJob;
use App\Domain\SourceReference\Models\SourceReference;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('requires a read token for sources', function (): void {
    $this->getJson('/api/source-references')->assertUnauthorized();
    $this->getJson('/api/source-references/1')->assertUnauthorized();

    $user = User::factory()->create();
    $this->withToken($user->createToken('No access', [])->plainTextToken)
        ->getJson('/api/source-references')->assertForbidden();
});

it('reads owned sources with captured HTML and processing status without user data', function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local']);
    $user = User::factory()->create();
    $other = User::factory()->create();
    $attributes = [
        'url' => 'https://example.com/source', 'status' => SourceReferenceStatus::Failed,
        'raw_content' => '<html><body><h1>Captured page</h1></body></html>',
        'screenshot_path' => 'screenshots/example.png',
        'metadata' => ['raw_content' => ['status' => 'COMPLETED'], 'screenshot' => ['status' => 'FAILED', 'error' => 'Timeout']],
    ];
    $source = SourceReference::create(['user_id' => $user->id, ...$attributes]);
    $otherSource = SourceReference::create(['user_id' => $other->id, ...$attributes]);

    $this->withToken($user->createToken('Reader', ['read'])->plainTextToken);
    $this->getJson('/api/source-references')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $source->id)->assertJsonMissingPath('data.0.user_id');
    $response = $this->getJson('/api/source-references/'.$source->id)->assertOk()
        ->assertJsonPath('data.url', $source->url)->assertJsonPath('data.status', 'FAILED')
        ->assertJsonPath('data.raw_content', $attributes['raw_content'])
        ->assertJsonPath('data.metadata.screenshot.error', 'Timeout')
        ->assertJsonPath('data.screenshot_path', 'screenshots/example.png')
        ->assertJsonMissingPath('data.user_id')->assertJsonMissingPath('data.user');
    expect($response->json('data.image_url'))->toBeString()->toContain('/screenshots/example.png');
    $this->getJson('/api/source-references/'.$otherSource->id)->assertNotFound();
    $this->getJson('/api/source-references/99999')->assertNotFound();
});

it('paginates sources without skipping or exposing another users records', function (): void {
    $user = User::factory()->create();
    $ids = collect(range(1, 26))->map(fn () => SourceReference::create([
        'user_id' => $user->id, 'url' => 'https://example.com', 'status' => SourceReferenceStatus::Pending,
    ])->id);
    SourceReference::create([
        'user_id' => User::factory()->create()->id, 'url' => 'https://private.example.com', 'status' => SourceReferenceStatus::New,
    ]);

    $this->withToken($user->createToken('Reader', ['read'])->plainTextToken);
    $this->getJson('/api/source-references')->assertOk()->assertJsonCount(25, 'data')
        ->assertJsonPath('meta.total', 26)->assertJsonPath('data.0.id', $ids->last());
    $this->getJson('/api/source-references?page=2')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ids->first())->assertJsonPath('data.0.raw_content', null)
        ->assertJsonPath('data.0.image_url', null)->assertJsonPath('links.next', null);
});

it('adds a URL for the token owner and queues normal source processing', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $other = User::factory()->create();
    $url = 'https://example.com/article?a=1&b=two%20words#section';
    $this->withToken($user->createToken('Integration', ['read', 'sources:create'])->plainTextToken);

    $response = $this->postJson('/api/source-references', [
        'url' => $url, 'user_id' => $other->id, 'status' => 'COMPLETED', 'raw_content' => 'Injected content',
    ])->assertCreated()->assertJsonPath('data.url', $url)->assertJsonPath('data.status', 'NEW')
        ->assertJsonPath('data.raw_content', null)->assertJsonPath('data.metadata.raw_content.status', 'PENDING')
        ->assertJsonPath('data.metadata.screenshot.status', 'PENDING')->assertJsonMissingPath('data.user_id');

    $this->assertDatabaseCount('source_references', 1);
    $this->assertDatabaseHas('source_references', [
        'id' => $response->json('data.id'), 'user_id' => $user->id, 'url' => $url, 'status' => 'NEW',
    ]);
    Bus::assertDispatched(ProcessSourceReferenceJob::class);
    $this->getJson('/api/source-references/'.$response->json('data.id'))->assertOk()->assertJsonPath('data.url', $url);
});

it('rejects API URL submissions without a sources:create token', function (): void {
    Bus::fake();
    $this->postJson('/api/source-references', ['url' => 'https://example.com'])->assertUnauthorized();
    $user = User::factory()->create();
    $this->actingAs($user, 'web')->postJson('/api/source-references', ['url' => 'https://example.com'])->assertUnauthorized();
    $this->withToken($user->createToken('Old read-only token', ['read'])->plainTextToken)
        ->postJson('/api/source-references', ['url' => 'https://example.com'])->assertForbidden();

    $this->assertDatabaseCount('source_references', 0);
    Bus::assertNothingDispatched();
});

it('validates API URLs before saving or queuing processing', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $this->withToken($user->createToken('Integration', ['read', 'sources:create'])->plainTextToken);

    foreach ([[], ['url' => 'not a URL'], ['url' => 'ftp://example.com/file'], ['url' => 'https://example.com/'.str_repeat('a', 2048)]] as $payload) {
        $this->postJson('/api/source-references', $payload)->assertUnprocessable()->assertJsonValidationErrors('url');
    }

    $this->assertDatabaseCount('source_references', 0);
    Bus::assertNothingDispatched();
});
