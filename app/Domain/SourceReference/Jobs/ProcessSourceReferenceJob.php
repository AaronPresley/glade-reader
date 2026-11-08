<?php

namespace App\Domain\SourceReference\Jobs;

use App\Domain\Entry\Actions\GenerateArticleEntryAction;
use App\Domain\SourceReference\Actions\ResolveReadingTarget;
use App\Domain\SourceReference\Enums\SourceReferenceStatus;
use App\Domain\SourceReference\Enums\SourceReferenceStepStatus;
use App\Domain\SourceReference\Models\SourceReference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

class ProcessSourceReferenceJob implements ShouldQueue
{
    use FoundationQueueable, Queueable;

    public function __construct(
        public SourceReference $sourceReference,
    ) {}

    public function handle(): void
    {
        $this->sourceReference->update([
            'status' => SourceReferenceStatus::Pending,
        ]);

        // Resolve before launching either paid capture: a linked HN story needs the
        // publisher's article in both jobs, not article HTML with discussion imagery.
        try {
            app(ResolveReadingTarget::class)->handle($this->sourceReference);
        } catch (Throwable $exception) {
            SourceReference::whereKey($this->sourceReference->id)->update([
                'status' => SourceReferenceStatus::Failed,
                'metadata->resolution->status' => SourceReferenceStepStatus::Failed->value,
                'metadata->resolution->error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $sourceReferenceId = $this->sourceReference->id;

        Bus::batch([
            new GetSourceReferenceScreenshotJob($this->sourceReference),
            new GetSourceReferenceRawContentJob($this->sourceReference),
        ])->allowFailures()->finally(function () use ($sourceReferenceId): void {
            $sourceReference = SourceReference::findOrFail($sourceReferenceId);
            $metadata = $sourceReference->metadata ?? [];

            // The current article recipe needs both inputs: HTML supplies the wording,
            // while the screenshot helps distinguish article regions from page clutter.
            // A partial capture must not produce a misleading "completed" article.
            $rawContentCompleted = ($metadata['raw_content']['status'] ?? null) === SourceReferenceStepStatus::Completed->value;
            $screenshotCompleted = ($metadata['screenshot']['status'] ?? null) === SourceReferenceStepStatus::Completed->value;

            $sourceReference->update([
                'status' => $rawContentCompleted && $screenshotCompleted
                    ? SourceReferenceStatus::Completed
                    : SourceReferenceStatus::Failed,
            ]);

            if ($rawContentCompleted && $screenshotCompleted) {
                $sourceReference->loadMissing('user');

                app(GenerateArticleEntryAction::class)->handle($sourceReference->user, $sourceReference);
            }
        })->dispatch();
    }

    public function failed(?Throwable $exception): void
    {
        $this->sourceReference->update([
            'status' => SourceReferenceStatus::Failed,
            'metadata->process->error' => $this->failureMessage($exception),
        ]);
    }

    private function failureMessage(?Throwable $exception): string
    {
        if ($exception?->getMessage()) {
            return $exception->getMessage();
        }

        return 'Unable to process source reference.';
    }
}
