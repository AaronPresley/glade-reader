<?php

namespace App\Domain\SourceReference\Controllers;

use App\Domain\Entry\Enums\EntryType;
use App\Domain\Entry\Models\Entry;
use App\Domain\SourceReference\Actions\CheckSourceReference;
use App\Domain\SourceReference\Actions\DeleteSourceReferenceAction;
use App\Domain\SourceReference\Models\SourceReference;
use App\Domain\SourceReference\Requests\CheckSourceReferenceRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class SourceReferenceController extends Controller
{
    public function index(Request $request): Response
    {
        $sourceReferences = $request->user()
            ->sourceReferences()
            ->latest()
            ->get()
            ->map(fn (SourceReference $sourceReference): array => [
                'id' => $sourceReference->id,
                'url' => $sourceReference->url,
                'status' => $sourceReference->status->value,
                'created_at' => $sourceReference->created_at?->toIso8601String(),
            ]);

        return Inertia::render('source-references/index', [
            'sourceReferences' => $sourceReferences,
        ]);
    }

    public function create(Request $request): Response
    {
        $addSourceReferenceUrl = route('source-references.add', [
            'token' => $request->user()->share_token,
        ]);

        return Inertia::render('source-references/create', [
            'shareToken' => $request->user()->share_token,
            'addSourceReferenceUrl' => $addSourceReferenceUrl,
            'addSourceReferenceUrlTemplate' => "{$addSourceReferenceUrl}?url={url}",
            'regenerateShareTokenUrl' => route('source-references.share-token.regenerate'),
            'bookmarkletUrl' => URL::signedRoute('source-references.bookmarklet', [
                'user' => $request->user()->id,
            ]),
        ]);
    }

    public function store(CheckSourceReferenceRequest $request, CheckSourceReference $checkSourceReference): RedirectResponse
    {
        $checkSourceReference->handle(
            user: $request->user(),
            url: $request->validated('url'),
        );

        return redirect()->route('entries.index');
    }

    public function show(Request $request, SourceReference $sourceReference): Response
    {
        $this->authorizeSourceReference($request, $sourceReference);

        return Inertia::render('source-references/show', [
            'sourceReference' => [
                'id' => $sourceReference->id,
                'user_id' => $sourceReference->user_id,
                'url' => $sourceReference->url,
                'status' => $sourceReference->status->value,
                'raw_content' => $sourceReference->raw_content,
                'screenshot_path' => $sourceReference->screenshot_path,
                'metadata' => $sourceReference->metadata,
                'created_at' => $sourceReference->created_at?->toIso8601String(),
                'updated_at' => $sourceReference->updated_at?->toIso8601String(),
                'image_url' => $sourceReference->screenshot_path
                    ? Storage::temporaryUrl($sourceReference->screenshot_path, now()->addMinutes(30))
                    : null,
                'article_entry' => $this->articleEntrySummary($sourceReference),
            ],
        ]);
    }

    public function destroy(
        Request $request,
        SourceReference $sourceReference,
        DeleteSourceReferenceAction $deleteSourceReference,
    ): RedirectResponse {
        $this->authorizeSourceReference($request, $sourceReference);

        $deleteSourceReference->handle($sourceReference);

        return redirect()->route('source-references.index');
    }

    private function authorizeSourceReference(Request $request, SourceReference $sourceReference): void
    {
        abort_unless($sourceReference->user_id === $request->user()->id, 404);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function articleEntrySummary(SourceReference $sourceReference): ?array
    {
        $entry = Entry::query()
            ->where('user_id', $sourceReference->user_id)
            ->where('source_reference_id', $sourceReference->id)
            ->where('type', EntryType::Article->value)
            ->latest()
            ->first();

        if (! $entry instanceof Entry) {
            return null;
        }

        return [
            'id' => $entry->id,
            'type' => $entry->type instanceof \BackedEnum ? $entry->type->value : $entry->type,
            'status' => $entry->status instanceof \BackedEnum ? $entry->status->value : $entry->status,
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ];
    }
}
