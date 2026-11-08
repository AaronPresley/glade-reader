<?php

namespace App\Domain\SourceReference\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class SourceReferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'content_url' => $this->content_url,
            'status' => $this->status->value,
            'raw_content' => $this->raw_content,
            'screenshot_path' => $this->screenshot_path,
            'image_url' => $this->screenshot_path
                ? Storage::temporaryUrl($this->screenshot_path, now()->addMinutes(30))
                : null,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
