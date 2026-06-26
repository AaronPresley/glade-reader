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

        SourceReference::query()
            ->whereKey($this->sourceReference->id)
            ->update([
                'metadata->screenshot->error' => null,
            ]);

        $requestedAt = $this->sourceReference->created_at ?? now();
        $path = sprintf(
            '%s/source-references/%s-%s.png',
            app()->environment(),
            $requestedAt->format('Y-m-d-H-i-s'),
            $this->sourceReference->id,
        );

        if (! Storage::put($path, $getScreenshotFromUrl->handle($this->sourceReference->url))) {
            throw new RuntimeException('Unable to store source reference screenshot.');
        }

        SourceReference::query()
            ->whereKey($this->sourceReference->id)
            ->update([
                'screenshot_path' => $path,
                'metadata->screenshot->status' => SourceReferenceStepStatus::Completed->value,
            ]);

        SourceReference::query()
            ->whereKey($this->sourceReference->id)
            ->update([
                'metadata->screenshot->error' => null,
            ]);
    }

    public function failed(?Throwable $exception): void
    {
        SourceReference::query()
            ->whereKey($this->sourceReference->id)
            ->update([
                'metadata->screenshot->status' => SourceReferenceStepStatus::Failed->value,
            ]);

        SourceReference::query()
            ->whereKey($this->sourceReference->id)
            ->update([
                'metadata->screenshot->error' => $this->failureMessage($exception),
            ]);
    }

    private function failureMessage(?Throwable $exception): string
    {
        if ($exception?->getMessage()) {
            return $exception->getMessage();
        }

        return 'Unable to get screenshot for source reference.';
    }
}
