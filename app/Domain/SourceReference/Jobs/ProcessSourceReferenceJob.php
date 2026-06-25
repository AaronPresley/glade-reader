<?php

namespace App\Domain\SourceReference\Jobs;

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

        $sourceReferenceId = $this->sourceReference->id;

        Bus::batch([
            new GetSourceReferenceScreenshotJob($this->sourceReference),
            new GetSourceReferenceRawContentJob($this->sourceReference),
        ])->allowFailures()->finally(function () use ($sourceReferenceId): void {
            $sourceReference = SourceReference::findOrFail($sourceReferenceId);
            $metadata = $sourceReference->metadata ?? [];

            $rawContentCompleted = ($metadata['raw_content']['status'] ?? null) === SourceReferenceStepStatus::Completed->value;
            $screenshotCompleted = ($metadata['screenshot']['status'] ?? null) === SourceReferenceStepStatus::Completed->value;

            $sourceReference->update([
                'status' => $rawContentCompleted && $screenshotCompleted
                    ? SourceReferenceStatus::Completed
                    : SourceReferenceStatus::Failed,
            ]);
        })->dispatch();
    }

    public function failed(?Throwable $exception): void
    {
        $this->sourceReference->update([
            'status' => SourceReferenceStatus::Failed,
        ]);
    }
}
