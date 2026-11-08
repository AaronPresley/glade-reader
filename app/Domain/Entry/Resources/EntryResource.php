<?php

namespace App\Domain\Entry\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class EntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_reference_id' => $this->source_reference_id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'content' => $this->content,
            'content_html' => $this->content === null ? null : Str::markdown($this->content, [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]),
            'metadata' => $this->metadata,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
