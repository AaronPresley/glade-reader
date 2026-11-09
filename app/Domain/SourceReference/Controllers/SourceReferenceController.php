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
use Illuminate\Http\Response as HttpResponse;
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
        return Inertia::render('source-references/create', [
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
                'content_url' => $sourceReference->content_url,
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

    public function diagram(Request $request, SourceReference $sourceReference, string $diagram): HttpResponse
    {
        $this->authorizeSourceReference($request, $sourceReference);
        abort_if($request->user()->currentAccessToken() && ! $request->user()->tokenCan('read'), 403);
        $path = $sourceReference->diagramDirectory().'/'.$diagram.'.svg';
        abort_unless(Storage::exists($path), 404);

        // Treat captured SVG as an untrusted image, never inline article HTML.
        // Sandbox direct navigation too, blocking scripts and external resources.
        return response(Storage::get($path), 200, [
            'Content-Type' => 'image/svg+xml',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-cache',
        ]);
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
