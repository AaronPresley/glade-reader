<?php

use App\Domain\Entry\Enums\EntryStatus;
use App\Domain\Entry\Enums\EntryType;
use App\Domain\Entry\Models\Entry;
use App\Domain\SourceReference\Enums\SourceReferenceStatus;
use App\Domain\SourceReference\Models\SourceReference;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
