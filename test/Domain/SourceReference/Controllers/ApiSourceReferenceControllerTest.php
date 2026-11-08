<?php

use App\Domain\SourceReference\Enums\SourceReferenceStatus;
use App\Domain\SourceReference\Models\SourceReference;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
