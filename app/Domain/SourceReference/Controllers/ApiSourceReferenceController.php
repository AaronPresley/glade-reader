<?php

namespace App\Domain\SourceReference\Controllers;

use App\Domain\SourceReference\Actions\CheckSourceReference;
use App\Domain\SourceReference\Requests\CheckSourceReferenceRequest;
use App\Domain\SourceReference\Resources\SourceReferenceResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ApiSourceReferenceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return SourceReferenceResource::collection($request->user()->sourceReferences()->orderByDesc('id')->paginate(25));
    }

    public function show(Request $request, int $sourceReference): SourceReferenceResource
    {
        return new SourceReferenceResource($request->user()->sourceReferences()->findOrFail($sourceReference));
    }

    public function store(CheckSourceReferenceRequest $request, CheckSourceReference $checkSourceReference): JsonResponse
    {
        $sourceReference = $checkSourceReference->handle(
            user: $request->user(),
            url: $request->validated('url'),
        );

        return (new SourceReferenceResource($sourceReference))->response()->setStatusCode(201);
    }
}
