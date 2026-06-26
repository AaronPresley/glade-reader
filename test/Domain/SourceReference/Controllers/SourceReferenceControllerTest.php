<?php

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

    $this->actingAs($user)
        ->get('/source-references/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('source-references/create'));
});

it('stores a source reference', function (): void {
    Bus::fake();

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post('/source-references', [
            'url' => 'https://example.com/source',
        ]);

    $sourceReference = SourceReference::firstOrFail();

    $response->assertRedirect(route('source-references.show', $sourceReference));

    $this->assertDatabaseHas('source_references', [
        'id' => $sourceReference->id,
        'user_id' => $user->id,
        'url' => 'https://example.com/source',
        'status' => SourceReferenceStatus::New->value,
    ]);

    Bus::assertDispatched(ProcessSourceReferenceJob::class);
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
