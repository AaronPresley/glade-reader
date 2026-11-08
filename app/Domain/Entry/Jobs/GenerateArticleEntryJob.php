<?php

namespace App\Domain\Entry\Jobs;

use App\Domain\Entry\Enums\EntryStatus;
use App\Domain\Entry\Models\Entry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class GenerateArticleEntryJob implements ShouldQueue
{
    use FoundationQueueable, Queueable;

    // One bounded provider call per job: finish before the default 90s visibility
    // timeout, regardless of how many chunks the complete page requires.
    public int $timeout = 80;

    public ?string $runId = null;

    public int $offset = 0;

    public function __construct(
        public Entry $entry,
    ) {
        $this->runId = $entry->metadata['generation']['run_id'] ?? null;
        $this->offset = $entry->metadata['generation']['checkpoint']['next_offset'] ?? 0;
    }

    public function handle(): void
    {
        try {
            $entry = DB::transaction(function (): ?Entry {
                $entry = Entry::with('sourceReference')->lockForUpdate()->find($this->entry->id);

                if (! $entry instanceof Entry) {
                    throw new RuntimeException('Article entry no longer exists.');
                }

                if (! $this->isCurrent($entry)) {
                    return null;
                }

                $entry->update([
                    'status' => EntryStatus::Processing->value,
                    'metadata' => $this->metadataWithGeneration($entry->metadata, ['error' => null]),
                ]);

                return $entry;
            });

            if ($entry === null) {
                return;
            }

            $flow = $entry->sourceReference->metadata['reading']['flow'] ?? 'article';
            $result = app(config('reading.flows.'.$flow.'.generator'))->handle(
                $entry->sourceReference, $entry->metadata['generation']['checkpoint'] ?? [],
            );

            // Do not hold a database lock during the provider call. Recheck the
            // run under lock before saving so a manual reprocess always wins.
            DB::transaction(function () use ($result): void {
                $entry = Entry::with('sourceReference')->lockForUpdate()->find($this->entry->id);

                if (! $entry instanceof Entry || ! $this->isCurrent($entry)) {
                    return;
                }

                $metadata = $this->metadataWithGeneration($entry->metadata, [
                    'provider' => $result['provider'],
                    'model' => $result['model'],
                    'usage' => $result['usage'],
                    'raw_html_truncated' => $result['raw_html_truncated'],
                    'extraction' => $result['extraction'] ?? null,
                    'checkpoint' => $result['checkpoint'] ?? null,
                    'error' => null,
                ]);

                if (isset($result['checkpoint'])) {
                    // With the database queue, progress and its continuation commit
                    // together. Worker retries reuse completed plans, not paid calls.
                    $entry->update(['metadata' => $metadata]);
                    self::dispatch($entry);

                    return;
                }

                // Keep the layout and URL base with the content they describe. Future site
                // rules or source retries must not reinterpret an already generated read;
                // opening it should use stored data, without fresh provider calls or costs.
                $metadata['reading'] = [
                    ...($entry->sourceReference->metadata['reading'] ?? ['layout' => 'default', 'flow' => 'article']),
                    'content_url' => $entry->sourceReference->content_url ?? $entry->sourceReference->url,
                ];

                $entry->update([
                    'content' => $result['markdown'],
                    'status' => EntryStatus::Completed->value,
                    'metadata' => $metadata,
                ]);
            });
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
        DB::transaction(function () use ($exception): void {
            $entry = Entry::lockForUpdate()->find($this->entry->id);

            if (! $entry instanceof Entry || ! $this->isCurrent($entry)) {
                return;
            }

            $entry->update([
                'status' => EntryStatus::Failed->value,
                'metadata' => $this->metadataWithGeneration($entry->metadata, [
                    'error' => $this->failureMessage($exception),
                ]),
            ]);
        });
    }

    private function failureMessage(?Throwable $exception): string
    {
        if ($exception?->getMessage()) {
            return $exception->getMessage();
        }

        return 'Unable to generate article entry.';
    }

    private function isCurrent(Entry $entry): bool
    {
        return $entry->status !== EntryStatus::Completed
            && ($entry->metadata['generation']['run_id'] ?? null) === $this->runId
            && ($entry->metadata['generation']['checkpoint']['next_offset'] ?? 0) === $this->offset;
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
