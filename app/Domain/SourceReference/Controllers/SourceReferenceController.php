<?php

namespace App\Domain\SourceReference\Controllers;

use App\Domain\SourceReference\Actions\CheckSourceReference;
use App\Domain\SourceReference\Actions\DeleteSourceReferenceAction;
use App\Domain\SourceReference\Models\SourceReference;
use App\Domain\SourceReference\Requests\CheckSourceReferenceRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

    public function create(): Response
    {
        return Inertia::render('source-references/create');
    }

    public function store(CheckSourceReferenceRequest $request, CheckSourceReference $checkSourceReference): RedirectResponse
    {
        $sourceReference = $checkSourceReference->handle(
            user: $request->user(),
            url: $request->validated('url'),
        );

        return redirect()->route('source-references.show', $sourceReference);
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
}
