<?php

namespace App\Domain\Entry\Controllers;

use App\Domain\Entry\Actions\GenerateArticleEntryAction;
use App\Domain\SourceReference\Models\SourceReference;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SourceReferenceArticleEntryController extends Controller
{
    public function store(
        Request $request,
        SourceReference $sourceReference,
        GenerateArticleEntryAction $generateArticleEntry,
    ): RedirectResponse {
        abort_unless($sourceReference->user_id === $request->user()->id, 404);

        try {
            $entry = $generateArticleEntry->handle($request->user(), $sourceReference);
        } catch (RuntimeException $exception) {
            return back()->withErrors([
                'source_reference_id' => $exception->getMessage(),
            ]);
        }

        return redirect()->route('entries.show', $entry);
    }
}
