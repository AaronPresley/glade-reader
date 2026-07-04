<?php

namespace App\Domain\Entry\Models;

use App\Domain\Entry\Enums\EntryStatus;
use App\Domain\Entry\Enums\EntryType;
use App\Domain\SourceReference\Models\SourceReference;
use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'source_reference_id', 'type', 'status', 'content', 'metadata'])]
class Entry extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<SourceReference, $this>
     */
    public function sourceReference(): BelongsTo
    {
        return $this->belongsTo(SourceReference::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EntryType::class,
            'status' => EntryStatus::class,
            'metadata' => 'array',
        ];
    }
}
