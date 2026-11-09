<?php

namespace App\Domain\SourceReference\Models;

use App\Domain\Entry\Enums\EntryType;
use App\Domain\Entry\Models\Entry;
use App\Domain\SourceReference\Enums\SourceReferenceStatus;
use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['user_id', 'url', 'content_url', 'status', 'raw_content', 'screenshot_path', 'metadata'])]
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
     * @return HasMany<Entry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    /**
     * @return HasOne<Entry, $this>
     */
    public function articleEntry(): HasOne
    {
        return $this->hasOne(Entry::class)->where('type', EntryType::Article->value);
    }

    public function diagramDirectory(): string
    {
        // Capture assets survive entry regeneration and are removed with the source.
        return 'source-references/'.$this->id.'/diagrams';
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
