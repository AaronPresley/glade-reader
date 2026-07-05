<?php

use App\Domain\Entry\Enums\EntryStatus;
use App\Domain\Entry\Enums\EntryType;
use App\Domain\Entry\Jobs\GenerateArticleEntryJob;
use App\Domain\Entry\Models\Entry;
use App\Domain\SourceReference\Enums\SourceReferenceStatus;
use App\Domain\SourceReference\Models\SourceReference;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function createEntryControllerSourceReference(
    User $user,
    SourceReferenceStatus $status = SourceReferenceStatus::Completed,
    string $url = 'https://example.com/source',
    ?string $rawContent = null,
    ?string $screenshotPath = null,
): SourceReference {
    return SourceReference::create([
        'user_id' => $user->id,
        'url' => $url,
        'status' => $status,
        'raw_content' => $rawContent,
        'screenshot_path' => $screenshotPath,
    ]);
}

function createEntryControllerArticleEntry(
    User $user,
    SourceReference $sourceReference,
    array $attributes = [],
): Entry {
    return Entry::create([
        'user_id' => $user->id,
        'source_reference_id' => $sourceReference->id,
        'type' => EntryType::Article->value,
        'status' => $attributes['status'] ?? EntryStatus::Completed->value,
        'content' => $attributes['content'] ?? 'Article content',
        'metadata' => $attributes['metadata'] ?? null,
        'archived_at' => $attributes['archived_at'] ?? null,
    ]);
}

function createEntryControllerGeneratableSourceReference(
    User $user,
    string $url = 'https://example.com/source',
    string $screenshotPath = 'testing/source-references/example.png',
): SourceReference {
    Storage::put($screenshotPath, 'screenshot');

    return createEntryControllerSourceReference(
        user: $user,
        url: $url,
        rawContent: '<html><body>Article source</body></html>',
        screenshotPath: $screenshotPath,
    );
}

it('requires authentication for entries and source reference article generation', function (): void {
    $this->get('/entries')->assertRedirect('/login');
    $this->get('/entries/1')->assertRedirect('/login');
    $this->get('/entries/1/details')->assertRedirect('/login');
    $this->post('/entries/1/archive')->assertRedirect('/login');
    $this->delete('/entries/1')->assertRedirect('/login');
    $this->post('/source-references/1/entries/article')->assertRedirect('/login');
});

it('does not expose direct entry create or update routes', function (): void {
    $user = User::factory()->create();
    $sourceReference = createEntryControllerSourceReference($user);
    $entry = createEntryControllerArticleEntry($user, $sourceReference);

    $this->actingAs($user)
        ->get('/entries/create')
        ->assertNotFound();

    $this->actingAs($user)
        ->post('/entries', [])
        ->assertStatus(405);

    $this->actingAs($user)
        ->get("/entries/{$entry->id}/edit")
        ->assertNotFound();

    $this->actingAs($user)
        ->put("/entries/{$entry->id}", [])
        ->assertStatus(405);

    $this->actingAs($user)
        ->patch("/entries/{$entry->id}", [])
        ->assertStatus(405);
});

it('shows the authenticated users entries', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $sourceReference = createEntryControllerSourceReference($user);
    $entry = createEntryControllerArticleEntry($user, $sourceReference);

    $otherSourceReference = createEntryControllerSourceReference(
        $otherUser,
        url: 'https://other.example.com/source',
    );
    createEntryControllerArticleEntry($otherUser, $otherSourceReference);

    $this->actingAs($user)
        ->get('/entries')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('entries/index')
            ->has('unarchivedEntries', 1)
            ->where('unarchivedEntries.0.id', $entry->id)
            ->where('unarchivedEntries.0.type', EntryType::Article->value)
            ->where('unarchivedEntries.0.status', EntryStatus::Completed->value)
            ->where('unarchivedEntries.0.archived_at', null)
            ->where('unarchivedEntries.0.source_reference.url', 'https://example.com/source')
            ->has('archivedEntries', 0)
            ->where('sourceProcessing', null));
});

it('redirects the authenticated app landing page to entries', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect('/entries');
});

it('shows archived entries and source processing counts', function (): void {
    $user = User::factory()->create();
    $archivedSourceReference = createEntryControllerSourceReference(
        $user,
        url: 'https://example.com/archived',
    );
    $archivedEntry = createEntryControllerArticleEntry($user, $archivedSourceReference, [
        'archived_at' => now(),
    ]);
    createEntryControllerSourceReference(
        $user,
        status: SourceReferenceStatus::Pending,
        url: 'https://example.com/processing',
    );
    createEntryControllerSourceReference(
        $user,
        status: SourceReferenceStatus::Failed,
        url: 'https://example.com/failed',
    );

    $this->actingAs($user)
        ->get('/entries')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('entries/index')
            ->has('unarchivedEntries', 0)
            ->has('archivedEntries', 1)
            ->where('archivedEntries.0.id', $archivedEntry->id)
            ->where('sourceProcessing.processing', 1)
            ->where('sourceProcessing.failed', 1));
});

it('shows and deletes owned entries', function (): void {
    $user = User::factory()->create();
    $sourceReference = createEntryControllerSourceReference($user);
    $entry = createEntryControllerArticleEntry($user, $sourceReference);

    $this->actingAs($user)
        ->get("/entries/{$entry->id}/details")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('entries/show')
            ->where('entry.id', $entry->id)
            ->where('entry.content', 'Article content'));

    $this->actingAs($user)
        ->delete("/entries/{$entry->id}")
        ->assertRedirect('/entries');

    $this->assertDatabaseMissing('entries', [
        'id' => $entry->id,
    ]);
});

it('renders completed entries in the read view', function (): void {
    $user = User::factory()->create();
    $sourceReference = createEntryControllerSourceReference($user);
    $entry = createEntryControllerArticleEntry($user, $sourceReference, [
        'content' => "# Article Title\n\nArticle body.",
    ]);

    $this->actingAs($user)
        ->get("/entries/{$entry->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('entries/read')
            ->where('entry.id', $entry->id)
            ->where('entry.content', "# Article Title\n\nArticle body.")
            ->where('entry.source_reference.id', $sourceReference->id)
            ->where('entry.source_reference.url', 'https://example.com/source'));
});

it('redirects incomplete entries from the read route to details', function (): void {
    $user = User::factory()->create();
    $sourceReference = createEntryControllerSourceReference($user);
    $entry = createEntryControllerArticleEntry($user, $sourceReference, [
        'status' => EntryStatus::Pending->value,
    ]);

    $this->actingAs($user)
        ->get("/entries/{$entry->id}")
        ->assertRedirect(route('entries.details', $entry));
});

it('archives owned entries', function (): void {
    $user = User::factory()->create();
    $sourceReference = createEntryControllerSourceReference($user);
    $entry = createEntryControllerArticleEntry($user, $sourceReference);

    $this->actingAs($user)
        ->post("/entries/{$entry->id}/archive")
        ->assertRedirect('/entries');

    expect($entry->refresh()->archived_at)->not->toBeNull();
});

it('does not allow cross-user entry access or mutation', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherSourceReference = createEntryControllerSourceReference($otherUser, url: 'https://other.example.com/source');
    $otherEntry = createEntryControllerArticleEntry($otherUser, $otherSourceReference);

    $this->actingAs($user)
        ->get("/entries/{$otherEntry->id}")
        ->assertNotFound();

    $this->actingAs($user)
        ->get("/entries/{$otherEntry->id}/details")
        ->assertNotFound();

    $this->actingAs($user)
        ->post("/entries/{$otherEntry->id}/archive")
        ->assertNotFound();

    $this->actingAs($user)
        ->delete("/entries/{$otherEntry->id}")
        ->assertNotFound();

    $this->assertDatabaseHas('entries', [
        'id' => $otherEntry->id,
        'content' => 'Article content',
    ]);
});

it('rejects article generation for incomplete source references', function (): void {
    Bus::fake();

    $user = User::factory()->create();
    $sourceReference = createEntryControllerSourceReference($user, SourceReferenceStatus::New);

    $this->actingAs($user)
        ->from("/source-references/{$sourceReference->id}")
        ->post("/source-references/{$sourceReference->id}/entries/article")
        ->assertRedirect("/source-references/{$sourceReference->id}")
        ->assertSessionHasErrors('source_reference_id');

    Bus::assertNotDispatched(GenerateArticleEntryJob::class);
});

it('does not generate article entries for another users source reference', function (): void {
    Bus::fake();

    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $sourceReference = createEntryControllerSourceReference($otherUser);

    $this->actingAs($user)
        ->post("/source-references/{$sourceReference->id}/entries/article")
        ->assertNotFound();

    Bus::assertNotDispatched(GenerateArticleEntryJob::class);
});

it('dispatches article generation and redirects to a new article entry', function (): void {
    config(['filesystems.default' => 'local']);
    Bus::fake();
    Storage::fake('local');

    $user = User::factory()->create();
    $sourceReference = createEntryControllerGeneratableSourceReference($user);

    $response = $this->actingAs($user)
        ->post("/source-references/{$sourceReference->id}/entries/article")
        ->assertRedirect();

    $entry = Entry::firstOrFail();

    $response->assertRedirect(route('entries.details', $entry));

    $this->assertDatabaseHas('entries', [
        'id' => $entry->id,
        'user_id' => $user->id,
        'source_reference_id' => $sourceReference->id,
        'type' => EntryType::Article->value,
        'status' => EntryStatus::Pending->value,
        'content' => null,
    ]);

    Bus::assertDispatched(GenerateArticleEntryJob::class);
});

it('dispatches article generation and redirects to an existing article entry', function (): void {
    config(['filesystems.default' => 'local']);
    Bus::fake();
    Storage::fake('local');

    $user = User::factory()->create();
    $sourceReference = createEntryControllerGeneratableSourceReference($user);
    $entry = createEntryControllerArticleEntry($user, $sourceReference, [
        'content' => 'Existing article content',
    ]);

    $this->actingAs($user)
        ->post("/source-references/{$sourceReference->id}/entries/article")
        ->assertRedirect(route('entries.details', $entry));

    $this->assertDatabaseHas('entries', [
        'id' => $entry->id,
        'user_id' => $user->id,
        'source_reference_id' => $sourceReference->id,
        'type' => EntryType::Article->value,
        'status' => EntryStatus::Pending->value,
        'content' => null,
    ]);

    Bus::assertDispatched(GenerateArticleEntryJob::class);
});
