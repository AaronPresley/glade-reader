<?php

use App\Domain\Entry\Enums\EntryStatus;
use App\Domain\Entry\Enums\EntryType;
use App\Domain\Entry\Models\Entry;
use App\Domain\SourceReference\Enums\SourceReferenceStatus;
use App\Domain\SourceReference\Models\SourceReference;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('requires a valid read token to read entries', function (): void {
    $this->getJson('/api/entries')->assertUnauthorized();
    $this->withToken('invalid')->getJson('/api/entries/1')->assertUnauthorized();

    $user = User::factory()->create();
    $this->withToken($user->createToken('No access', [])->plainTextToken)
        ->getJson('/api/entries')->assertForbidden();
});

it('does not authenticate the API with a browser session', function (): void {
    $this->actingAs(User::factory()->create(), 'web')->getJson('/api/entries')->assertUnauthorized();
    $this->postJson('/api/entries/1/regenerate')->assertUnauthorized();
});

it('requires the generation ability rather than read or URL submission access', function (): void {
    $this->postJson('/api/entries/1/regenerate')->assertUnauthorized();
    $this->withToken('invalid')->postJson('/api/entries/1/regenerate')->assertUnauthorized();

    $user = User::factory()->create();
    foreach ([[], ['read'], ['read', 'sources:create']] as $abilities) {
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('Existing integration', $abilities)->plainTextToken)
            ->postJson('/api/entries/1/regenerate')->assertForbidden();
    }
});

it('queues regeneration of only an owned entry using its existing saved capture', function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local', 'queue.default' => 'database']);
    Storage::put('captures/article.png', 'saved screenshot');

    $user = User::factory()->create();
    $other = User::factory()->create();
    $entries = collect([$user, $other])->map(function (User $owner): Entry {
        $source = SourceReference::create([
            'user_id' => $owner->id, 'url' => 'https://example.com/article',
            'status' => SourceReferenceStatus::Completed,
            'raw_content' => '<article><p>Saved article.</p></article>',
            'screenshot_path' => 'captures/article.png',
        ]);

        return Entry::create([
            'user_id' => $owner->id, 'source_reference_id' => $source->id,
            'type' => EntryType::Article, 'status' => EntryStatus::Failed,
            'content' => 'Old content',
            'metadata' => ['generation' => ['run_id' => 'old-run', 'error' => 'Old failure']],
        ]);
    });

    $this->withToken($user->createToken('Generator', ['entries:generate'])->plainTextToken);
    $this->postJson('/api/entries/'.$entries[1]->id.'/regenerate')->assertNotFound();
    $this->postJson('/api/entries/99999/regenerate')->assertNotFound();
    $this->assertDatabaseCount('jobs', 0);

    $response = $this->postJson('/api/entries/'.$entries[0]->id.'/regenerate')->assertAccepted()
        ->assertJsonPath('data.id', $entries[0]->id)->assertJsonPath('data.status', 'PENDING');
    $entry = $entries[0]->fresh();
    expect(Str::isUuid($response->json('data.run_id')))->toBeTrue();
    expect($entry->metadata['generation'])->toBe(['run_id' => $response->json('data.run_id')]);
    expect($entry->content)->toBeNull();
    expect($entry->source_reference_id)->toBe($entries[0]->source_reference_id);
    expect($entry->sourceReference->raw_content)->toBe('<article><p>Saved article.</p></article>');
    expect($entry->sourceReference->status)->toBe(SourceReferenceStatus::Completed);
    Storage::assertExists('captures/article.png');
    $this->assertDatabaseCount('entries', 2);
    $this->assertDatabaseCount('source_references', 2);
    $this->assertDatabaseCount('jobs', 1);

    Storage::delete('captures/article.png');
    $this->postJson('/api/entries/'.$entry->id.'/regenerate')->assertUnprocessable()
        ->assertJsonPath('message', 'Source reference screenshot file does not exist.');
    expect($entry->fresh()->metadata['generation']['run_id'])->toBe($response->json('data.run_id'));
    $this->assertDatabaseCount('jobs', 1);
});

it('reads only owned entries including archived entries and rendered HTML', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $entries = collect([$user, $other])->map(function (User $owner): Entry {
        $source = SourceReference::create([
            'user_id' => $owner->id, 'url' => 'https://example.com', 'status' => SourceReferenceStatus::Completed,
        ]);

        return Entry::create([
            'user_id' => $owner->id, 'source_reference_id' => $source->id,
            'type' => EntryType::Article, 'status' => EntryStatus::Completed,
            'content' => "# A title\n\nA **bold** body.\n\n<script>alert(1)</script>\n\n[bad](javascript:alert(1))",
            'metadata' => ['model' => 'example'], 'archived_at' => now(),
        ]);
    });

    $this->withToken($user->createToken('Reader', ['read'])->plainTextToken);
    $this->getJson('/api/entries')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $entries[0]->id)->assertJsonPath('meta.total', 1)
        ->assertJsonMissingPath('data.0.user_id');

    $response = $this->getJson('/api/entries/'.$entries[0]->id)->assertOk()
        ->assertJsonPath('data.status', 'COMPLETED')->assertJsonPath('data.type', 'ARTICLE')
        ->assertJsonPath('data.source_reference_id', $entries[0]->source_reference_id)
        ->assertJsonPath('data.content', $entries[0]->content)
        ->assertJsonPath('data.metadata.model', 'example')->assertJsonMissingPath('data.user_id');

    expect($response->json('data.archived_at'))->not->toBeNull();
    expect($response->json('data.content_html'))->toContain('<h1>A title</h1>', '<strong>bold</strong>')
        ->not->toContain('<script>', 'javascript:');

    $this->getJson('/api/entries/'.$entries[1]->id)->assertNotFound();
    $this->getJson('/api/entries/99999')->assertNotFound();

    $entries[0]->update(['status' => EntryStatus::Processing, 'content' => null]);
    $this->getJson('/api/entries/'.$entries[0]->id)->assertOk()
        ->assertJsonPath('data.status', 'PROCESSING')->assertJsonPath('data.content_html', null);
});

it('exposes no other API write or user endpoints', function (): void {
    $user = User::factory()->create();
    $this->withToken($user->createToken('Integration', ['read', 'sources:create'])->plainTextToken);

    $this->postJson('/api/entries', [])->assertStatus(405);
    foreach (['entries', 'source-references'] as $resource) {
        $this->putJson('/api/'.$resource.'/1', [])->assertStatus(405);
        $this->patchJson('/api/'.$resource.'/1', [])->assertStatus(405);
        $this->deleteJson('/api/'.$resource.'/1')->assertStatus(405);
    }

    $this->getJson('/api/users')->assertNotFound();
    $this->getJson('/api/user')->assertNotFound();
    $this->getJson('/api/users/'.$user->id)->assertNotFound();
    $this->postJson('/api/entries/1/archive')->assertNotFound();
    $this->deleteJson('/entries/1')->assertRedirect('/login');
    $this->postJson('/settings/api-tokens', ['name' => 'Escalated'])->assertRedirect('/login');
});
