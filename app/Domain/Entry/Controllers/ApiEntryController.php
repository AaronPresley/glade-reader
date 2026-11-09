<?php

namespace App\Domain\Entry\Controllers;

use App\Domain\Entry\Actions\GenerateArticleEntryAction;
use App\Domain\Entry\Resources\EntryResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class ApiEntryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return EntryResource::collection($request->user()->entries()->orderByDesc('id')->paginate(25));
    }

    public function show(Request $request, int $entry): EntryResource
    {
        return new EntryResource($request->user()->entries()->findOrFail($entry));
    }

    public function regenerate(Request $request, int $entry, GenerateArticleEntryAction $generate): JsonResponse
    {
        $entry = $request->user()->entries()->findOrFail($entry);

        // Regeneration reuses the saved capture; it must not recapture or create another entry.
        try {
            $entry = $generate->handle($request->user(), $entry->sourceReference);
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        return response()->json(['data' => [
            'id' => $entry->id,
            'status' => $entry->status->value,
            'run_id' => $entry->metadata['generation']['run_id'],
        ]], 202);
    }
}
