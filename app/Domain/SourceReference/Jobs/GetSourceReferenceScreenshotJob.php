<?php

namespace App\Domain\SourceReference\Jobs;

use App\Actions\GetScreenshotFromUrlAction;
use App\Domain\SourceReference\Enums\SourceReferenceStepStatus;
use App\Domain\SourceReference\Models\SourceReference;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class GetSourceReferenceScreenshotJob implements ShouldQueue
{
    use Batchable, FoundationQueueable, Queueable;

    public int $timeout = 360;

    public function __construct(
        public SourceReference $sourceReference,
    ) {}

    public function handle(GetScreenshotFromUrlAction $getScreenshotFromUrl): void
    {
        SourceReference::query()
            ->whereKey($this->sourceReference->id)
            ->update([
                'metadata->screenshot->status' => SourceReferenceStepStatus::Processing->value,
            ]);

        $path = 'source-references/'.$this->sourceReference->id.'/screenshot.png';

        if (! Storage::put($path, $getScreenshotFromUrl->handle($this->sourceReference->url))) {
            throw new RuntimeException('Unable to store source reference screenshot.');
        }

        SourceReference::query()
            ->whereKey($this->sourceReference->id)
            ->update([
                'screenshot_path' => $path,
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
