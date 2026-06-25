<?php

namespace App\Domain\SourceReference\Actions;

use App\Domain\SourceReference\Enums\SourceReferenceStatus;
use App\Domain\SourceReference\Enums\SourceReferenceStepStatus;
use App\Domain\SourceReference\Jobs\ProcessSourceReferenceJob;
use App\Domain\SourceReference\Models\SourceReference;

class CheckSourceReference
{
    public function handle(string $url): SourceReference
    {
        $sourceReference = SourceReference::create([
            'url' => $url,
            'status' => SourceReferenceStatus::New,
            'metadata' => [
                'raw_content' => [
                    'status' => SourceReferenceStepStatus::Pending->value,
                ],
                'screenshot' => [
                    'status' => SourceReferenceStepStatus::Pending->value,
                ],
            ],
        ]);

        ProcessSourceReferenceJob::dispatch($sourceReference);

        return $sourceReference;
    }
}
