<?php

namespace App\Domain\SourceReference\Actions;

use App\Domain\SourceReference\Enums\SourceReferenceStatus;
use App\Domain\SourceReference\Enums\SourceReferenceStepStatus;
use App\Domain\SourceReference\Jobs\ProcessSourceReferenceJob;
use App\Domain\SourceReference\Models\SourceReference;
use App\Domain\User\Models\User;

class CheckSourceReference
{
    public function handle(User $user, string $url): SourceReference
    {
        $sourceReference = SourceReference::create([
            'user_id' => $user->id,
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
