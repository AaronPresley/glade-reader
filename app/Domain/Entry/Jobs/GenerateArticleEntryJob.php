<?php

namespace App\Domain\Entry\Jobs;

use App\Domain\Entry\Actions\GenerateArticleMarkdownFromSourceReferenceAction;
use App\Domain\Entry\Enums\EntryStatus;
use App\Domain\Entry\Models\Entry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use RuntimeException;
use Throwable;

class GenerateArticleEntryJob implements ShouldQueue
{
    use FoundationQueueable, Queueable;

    public int $timeout = 180;

    public function __construct(
        public Entry $entry,
    ) {}

    public function handle(GenerateArticleMarkdownFromSourceReferenceAction $generateMarkdown): void
    {
        try {
            $entry = $this->entry->fresh(['sourceReference']);

            if (! $entry instanceof Entry) {
                throw new RuntimeException('Article entry no longer exists.');
            }

            $entry->update([
                'status' => EntryStatus::Processing->value,
                'metadata' => $this->metadataWithGeneration($entry->metadata, [
                    'error' => null,
                ]),
            ]);

            $result = $generateMarkdown->handle($entry->sourceReference);

            $entry->update([
                'content' => $result['markdown'],
                'status' => EntryStatus::Completed->value,
                'metadata' => $this->metadataWithGeneration($entry->metadata, [
                    'provider' => $result['provider'],
                    'model' => $result['model'],
                    'usage' => $result['usage'],
                    'raw_html_truncated' => $result['raw_html_truncated'],
                    'error' => null,
                ]),
            ]);
        } catch (Throwable $exception) {
            $this->markFailed($exception);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed($exception);
    }

    private function markFailed(?Throwable $exception): void
    {
        $entry = Entry::find($this->entry->id);

        if (! $entry instanceof Entry) {
            return;
        }

        $entry->update([
            'status' => EntryStatus::Failed->value,
            'metadata' => $this->metadataWithGeneration($entry->metadata, [
                'error' => $this->failureMessage($exception),
            ]),
        ]);
    }

    private function failureMessage(?Throwable $exception): string
    {
        if ($exception?->getMessage()) {
            return $exception->getMessage();
        }

        return 'Unable to generate article entry.';
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @param  array<string, mixed>  $generation
     * @return array<string, mixed>
     */
    private function metadataWithGeneration(?array $metadata, array $generation): array
    {
        $metadata ??= [];
        $existingGeneration = $metadata['generation'] ?? [];

        if (! is_array($existingGeneration)) {
            $existingGeneration = [];
        }

        $metadata['generation'] = array_merge($existingGeneration, $generation);

        return $metadata;
    }
}
