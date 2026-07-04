<?php

namespace App\Domain\Entry\Controllers;

use App\Domain\Entry\Enums\EntryStatus;
use App\Domain\Entry\Models\Entry;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EntryController extends Controller
{
    public function index(Request $request): Response
    {
        $entries = Entry::query()
            ->where('user_id', $request->user()->id)
            ->with('sourceReference')
            ->latest()
            ->get()
            ->map(fn (Entry $entry): array => $this->entrySummary($entry));

        return Inertia::render('entries/index', [
            'entries' => $entries,
        ]);
    }

    public function show(Request $request, Entry $entry): Response
    {
        $this->authorizeEntry($request, $entry);

        $entry->loadMissing('sourceReference');

        return Inertia::render('entries/show', [
            'entry' => $this->entryDetail($entry),
        ]);
    }

    public function read(Request $request, Entry $entry): RedirectResponse|Response
    {
        $this->authorizeEntry($request, $entry);

        if ($entry->status !== EntryStatus::Completed) {
            return redirect()->route('entries.show', $entry);
        }

        $entry->loadMissing('sourceReference');

        return Inertia::render('entries/read', [
            'entry' => [
                'id' => $entry->id,
                'content' => $entry->content,
                'source_reference' => $entry->sourceReference ? [
                    'id' => $entry->sourceReference->id,
                    'url' => $entry->sourceReference->url,
                ] : null,
            ],
        ]);
    }

    public function destroy(Request $request, Entry $entry): RedirectResponse
    {
        $this->authorizeEntry($request, $entry);

        $entry->delete();

        return redirect()->route('entries.index');
    }

    private function authorizeEntry(Request $request, Entry $entry): void
    {
        abort_unless($entry->user_id === $request->user()->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function entrySummary(Entry $entry): array
    {
        return [
            'id' => $entry->id,
            'source_reference_id' => $entry->source_reference_id,
            'type' => $this->enumValue($entry->type),
            'status' => $this->enumValue($entry->status),
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
            'source_reference' => $entry->sourceReference ? [
                'id' => $entry->sourceReference->id,
                'url' => $entry->sourceReference->url,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entryDetail(Entry $entry): array
    {
        return [
            ...$this->entrySummary($entry),
            'content' => $entry->content,
            'metadata' => $entry->metadata,
        ];
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
