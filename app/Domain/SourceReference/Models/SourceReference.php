<?php

namespace App\Domain\SourceReference\Models;

use App\Domain\SourceReference\Enums\SourceReferenceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['url', 'status', 'raw_content', 'screenshot_path', 'metadata'])]
class SourceReference extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SourceReferenceStatus::class,
            'metadata' => 'array',
        ];
    }
}
