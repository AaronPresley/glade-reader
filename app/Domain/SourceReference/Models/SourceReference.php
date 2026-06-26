<?php

namespace App\Domain\SourceReference\Models;

use App\Domain\SourceReference\Enums\SourceReferenceStatus;
use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'url', 'status', 'raw_content', 'screenshot_path', 'metadata'])]
class SourceReference extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

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
