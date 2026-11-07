<?php

namespace App\Domain\SourceReference\Controllers;

use App\Domain\SourceReference\Actions\CheckSourceReference;
use App\Domain\SourceReference\Requests\BookmarkletSourceReferenceRequest;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class BookmarkletSourceReferenceController extends Controller
{
    public function __invoke(
        BookmarkletSourceReferenceRequest $request,
        CheckSourceReference $checkSourceReference,
    ): Response {
        if ($request->isMethod('GET')) {
            return Inertia::render('source-references/bookmarklet', [
                'captureUrl' => $request->fullUrl(),
                'errorMessage' => null,
            ])->toResponse($request)->withHeaders([
                'Cache-Control' => 'no-store, private',
                'Referrer-Policy' => 'no-referrer',
            ]);
        }

        $checkSourceReference->handle(
            user: $request->user(),
            url: $request->validated('url'),
        );

        return Inertia::location($request->validated('url'));
    }
}
