<?php

use App\Domain\Entry\Enums\EntryStatus;
use App\Domain\Entry\Enums\EntryType;
use App\Domain\Entry\Models\Entry;
use App\Domain\SourceReference\Enums\SourceReferenceStatus;
use App\Domain\SourceReference\Jobs\ProcessSourceReferenceJob;
use App\Domain\SourceReference\Models\SourceReference;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('requires authentication for source references', function (): void {
    $this->get('/source-references')->assertRedirect('/login');
    $this->get('/source-references/create')->assertRedirect('/login');
    $this->post('/source-references', ['url' => 'https://example.com'])->assertRedirect('/login');
    $this->post('/source-references/share-token/regenerate')->assertRedirect('/login');
});

it('shows the authenticated users source references', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $sourceReference = SourceReference::create([
        'user_id' => $user->id,
        'url' => 'https://example.com',
        'status' => SourceReferenceStatus::Completed,
    ]);

    SourceReference::create([
        'user_id' => $otherUser->id,
        'url' => 'https://other.example.com',
        'status' => SourceReferenceStatus::Completed,
    ]);

    $this->actingAs($user)
        ->get('/source-references')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('source-references/index')
            ->has('sourceReferences', 1)
            ->where('sourceReferences.0.id', $sourceReference->id)
            ->where('sourceReferences.0.url', 'https://example.com')
            ->where('sourceReferences.0.status', SourceReferenceStatus::Completed->value));
});

it('shows create', function (): void {
    $user = User::factory()->create();
    $addSourceReferenceUrl = route('source-references.add', [
        'token' => $user->share_token,
    ]);

    $this->actingAs($user)
        ->get('/source-references/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('source-references/create')
            ->where('shareToken', $user->share_token)
            ->where('addSourceReferenceUrl', $addSourceReferenceUrl)
            ->where('addSourceReferenceUrlTemplate', "{$addSourceReferenceUrl}?url={url}")
            ->where('regenerateShareTokenUrl', route('source-references.share-token.regenerate')));
});

it('stores a source reference', function (): void {
    Bus::fake();

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post('/source-references', [
            'url' => 'https://example.com/source',
        ]);

    $sourceReference = SourceReference::firstOrFail();

    $response->assertRedirect(route('entries.index'));

    $this->assertDatabaseHas('source_references', [
        'id' => $sourceReference->id,
        'user_id' => $user->id,
        'url' => 'https://example.com/source',
        'status' => SourceReferenceStatus::New->value,
    ]);

    Bus::assertDispatched(ProcessSourceReferenceJob::class);
});

it('stores a source reference from the public get add route', function (): void {
    Bus::fake();

    $user = User::factory()->create();

    $this->get(route('source-references.add', [
        'token' => $user->share_token,
        'url' => 'https://example.com/public-get',
    ]))->assertNoContent();

    $this->assertDatabaseHas('source_references', [
        'user_id' => $user->id,
        'url' => 'https://example.com/public-get',
        'status' => SourceReferenceStatus::New->value,
    ]);

    Bus::assertDispatched(ProcessSourceReferenceJob::class);
});

it('stores a source reference from the public post add route', function (): void {
    Bus::fake();

    $user = User::factory()->create();

    $this->post(route('source-references.add', [
        'token' => $user->share_token,
    ]), [
        'url' => 'https://example.com/public-post',
    ])->assertNoContent();

    $this->assertDatabaseHas('source_references', [
        'user_id' => $user->id,
        'url' => 'https://example.com/public-post',
        'status' => SourceReferenceStatus::New->value,
    ]);

    Bus::assertDispatched(ProcessSourceReferenceJob::class);
});

it('returns json validation errors for invalid public add urls', function (): void {
    $user = User::factory()->create();

    $this->getJson(route('source-references.add', [
        'token' => $user->share_token,
        'url' => 'not-a-url',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('url');
});

it('returns json not found for invalid public add tokens', function (): void {
    $this->getJson(route('source-references.add', [
        'token' => 'invalid-token',
        'url' => 'https://example.com/source',
    ]))
        ->assertNotFound()
        ->assertJsonPath('message', 'Invalid share token.');
});

it('regenerates the authenticated users share token', function (): void {
    $user = User::factory()->create();
    $originalToken = $user->share_token;

    $this->actingAs($user)
        ->from('/source-references/create')
        ->post('/source-references/share-token/regenerate')
        ->assertRedirect('/source-references/create');

    expect($user->refresh()->share_token)->not->toBe($originalToken);
});

it('validates source reference urls', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/source-references', [
            'url' => 'not-a-url',
        ])
        ->assertSessionHasErrors('url');
});

it('shows a source reference', function (): void {
    $user = User::factory()->create();

    $sourceReference = SourceReference::create([
        'user_id' => $user->id,
        'url' => 'https://example.com',
        'status' => SourceReferenceStatus::Completed,
        'raw_content' => '{"title":"Example"}',
        'screenshot_path' => 'testing/source-references/example.png',
        'metadata' => ['raw_content' => ['status' => 'COMPLETED']],
    ]);

    $this->actingAs($user)
        ->get("/source-references/{$sourceReference->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('source-references/show')
            ->where('sourceReference.id', $sourceReference->id)
            ->where('sourceReference.url', 'https://example.com')
            ->where('sourceReference.status', SourceReferenceStatus::Completed->value)
            ->where('sourceReference.raw_content', '{"title":"Example"}')
            ->where('sourceReference.metadata.raw_content.status', 'COMPLETED')
            ->where('sourceReference.image_url', fn (string $value): bool => str_contains($value, 'testing/source-references/example.png')));
});

it('includes an existing article entry summary when showing a source reference', function (): void {
    $user = User::factory()->create();

    $sourceReference = SourceReference::create([
        'user_id' => $user->id,
        'url' => 'https://example.com',
        'status' => SourceReferenceStatus::Completed,
    ]);

    $entry = Entry::create([
        'user_id' => $user->id,
        'source_reference_id' => $sourceReference->id,
        'type' => EntryType::Article->value,
        'status' => EntryStatus::Completed->value,
        'content' => 'Article content',
    ]);

    $this->actingAs($user)
        ->get("/source-references/{$sourceReference->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('source-references/show')
            ->where('sourceReference.article_entry.id', $entry->id)
            ->where('sourceReference.article_entry.type', EntryType::Article->value)
            ->where('sourceReference.article_entry.status', EntryStatus::Completed->value));
});

it('deletes a source reference and its screenshot', function (): void {
    config(['filesystems.default' => 'local']);
    Storage::fake('local');

    $user = User::factory()->create();
    $path = 'testing/source-references/example.png';
    Storage::put($path, 'screenshot');

    $sourceReference = SourceReference::create([
        'user_id' => $user->id,
        'url' => 'https://example.com',
        'status' => SourceReferenceStatus::Completed,
        'screenshot_path' => $path,
    ]);

    $this->actingAs($user)
        ->delete("/source-references/{$sourceReference->id}")
        ->assertRedirect('/source-references');

    $this->assertDatabaseMissing('source_references', [
        'id' => $sourceReference->id,
    ]);

    Storage::assertMissing($path);
});

it('does not show another users source reference', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $sourceReference = SourceReference::create([
        'user_id' => $otherUser->id,
        'url' => 'https://example.com',
        'status' => SourceReferenceStatus::Completed,
    ]);

    $this->actingAs($user)
        ->get("/source-references/{$sourceReference->id}")
        ->assertNotFound();
});

it('does not delete another users source reference', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $sourceReference = SourceReference::create([
        'user_id' => $otherUser->id,
        'url' => 'https://example.com',
        'status' => SourceReferenceStatus::Completed,
    ]);

    $this->actingAs($user)
        ->delete("/source-references/{$sourceReference->id}")
        ->assertNotFound();

    $this->assertDatabaseHas('source_references', [
        'id' => $sourceReference->id,
    ]);
});
