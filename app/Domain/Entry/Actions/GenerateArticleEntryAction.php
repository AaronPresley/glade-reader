<?php

namespace App\Domain\Entry\Actions;

use App\Domain\Entry\Enums\EntryStatus;
use App\Domain\Entry\Enums\EntryType;
use App\Domain\Entry\Jobs\GenerateArticleEntryJob;
use App\Domain\Entry\Models\Entry;
use App\Domain\SourceReference\Enums\SourceReferenceStatus;
use App\Domain\SourceReference\Models\SourceReference;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class GenerateArticleEntryAction
{
    public function handle(User $user, SourceReference $sourceReference): Entry
    {
        abort_unless($sourceReference->user_id === $user->id, 404);

        $this->ensureSourceReferenceCanGenerateArticle($sourceReference);

        $attributes = [
            'user_id' => $user->id,
            'source_reference_id' => $sourceReference->id,
            'type' => EntryType::Article->value,
        ];

        $existingEntry = Entry::query()
            ->where($attributes)
            ->first();

        // A manual reprocess is a new run, not a continuation of an old checkpoint.
        // Its identity also prevents queued work from an older run overwriting it.
        $metadata = $existingEntry?->metadata ?? [];
        $metadata['generation'] = ['run_id' => (string) Str::uuid()];

        $entry = Entry::updateOrCreate($attributes, [
            'status' => EntryStatus::Pending->value,
            'content' => null,
            'metadata' => $metadata,
        ]);

        GenerateArticleEntryJob::dispatch($entry);

        return $entry;
    }

    private function ensureSourceReferenceCanGenerateArticle(SourceReference $sourceReference): void
    {
        if ($sourceReference->status !== SourceReferenceStatus::Completed) {
            throw new RuntimeException('Source reference must be completed before generating an article entry.');
        }

        if (! is_string($sourceReference->raw_content) || trim($sourceReference->raw_content) === '') {
            throw new RuntimeException('Source reference raw content is required before generating an article entry.');
        }

        if (! is_string($sourceReference->screenshot_path) || trim($sourceReference->screenshot_path) === '') {
            throw new RuntimeException('Source reference screenshot is required before generating an article entry.');
        }

        if (! Storage::exists($sourceReference->screenshot_path)) {
            throw new RuntimeException('Source reference screenshot file does not exist.');
        }
    }
}
