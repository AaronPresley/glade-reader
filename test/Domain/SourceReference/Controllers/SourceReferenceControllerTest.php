<?php

use App\Domain\Entry\Enums\EntryStatus;
use App\Domain\Entry\Enums\EntryType;
use App\Domain\Entry\Models\Entry;
use App\Domain\SourceReference\Enums\SourceReferenceStatus;
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

it('does not accept links outside the bookmarklet flow', function (): void {
    Bus::fake();
    $publicUrl = '/add/'.str_repeat('a', 64).'?url='.rawurlencode('https://example.com/source');

    $this->get($publicUrl)->assertNotFound();
    $this->head($publicUrl)->assertNotFound();
    $this->post($publicUrl)->assertNotFound();

    $this->actingAs(User::factory()->create());
    $this->get($publicUrl)->assertNotFound();
    $this->post($publicUrl)->assertNotFound();
    $this->post('/source-references', ['url' => 'https://example.com/source'])->assertStatus(405);
    $this->post('/source-references/share-token/regenerate')->assertNotFound();

    $this->assertDatabaseCount('source_references', 0);
    Bus::assertNothingDispatched();
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
