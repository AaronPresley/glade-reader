<?php

namespace App\Domain\SourceReference\Jobs;

use App\Domain\SourceReference\Enums\SourceReferenceStepStatus;
use App\Domain\SourceReference\Models\SourceReference;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Throwable;

class GetSourceReferenceScreenshotJob implements ShouldQueue
{
    use Batchable, FoundationQueueable, Queueable;

    public function __construct(
        public SourceReference $sourceReference,
    ) {}

    public function handle(): void
    {
        SourceReference::query()
            ->whereKey($this->sourceReference->id)
            ->update([
                'metadata->screenshot->status' => SourceReferenceStepStatus::Processing->value,
            ]);

        sleep(1);

        SourceReference::query()
            ->whereKey($this->sourceReference->id)
            ->update([
                'screenshot_path' => 'source-references/'.$this->sourceReference->id.'/screenshot.png',
                'metadata->screenshot->status' => SourceReferenceStepStatus::Completed->value,
            ]);
    }

    public function failed(?Throwable $exception): void
    {
        SourceReference::query()
            ->whereKey($this->sourceReference->id)
            ->update([
                'metadata->screenshot->status' => SourceReferenceStepStatus::Failed->value,
            ]);
    }
}
