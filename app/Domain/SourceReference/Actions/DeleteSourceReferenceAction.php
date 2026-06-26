<?php

namespace App\Domain\SourceReference\Actions;

use App\Domain\SourceReference\Models\SourceReference;
use Illuminate\Support\Facades\Storage;

class DeleteSourceReferenceAction
{
    public function handle(SourceReference $sourceReference): void
    {
        if ($sourceReference->screenshot_path) {
            Storage::delete($sourceReference->screenshot_path);
        }

        $sourceReference->delete();
    }
}
