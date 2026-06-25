<?php

namespace App\Domain\SourceReference\Jobs;

use App\Actions\GetRawContentFromUrlAction;
use App\Domain\SourceReference\Enums\SourceReferenceStepStatus;
use App\Domain\SourceReference\Models\SourceReference;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Throwable;

class GetSourceReferenceRawContentJob implements ShouldQueue
{
    use Batchable, FoundationQueueable, Queueable;

    public int $timeout = 360;

    public function __construct(
        public SourceReference $sourceReference,
    ) {}

    public function handle(GetRawContentFromUrlAction $getRawContentFromUrl): void
    {
        SourceReference::query()
            ->whereKey($this->sourceReference->id)
            ->update([
                'metadata->raw_content->status' => SourceReferenceStepStatus::Processing->value,
            ]);

        SourceReference::query()
            ->whereKey($this->sourceReference->id)
            ->update([
                'raw_content' => $getRawContentFromUrl->handle($this->sourceReference->url),
                'metadata->raw_content->status' => SourceReferenceStepStatus::Completed->value,
            ]);
    }

    public function failed(?Throwable $exception): void
    {
        SourceReference::query()
            ->whereKey($this->sourceReference->id)
            ->update([
                'metadata->raw_content->status' => SourceReferenceStepStatus::Failed->value,
            ]);
    }
}
