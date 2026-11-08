<?php

namespace App\Domain\SourceReference\Controllers;

use App\Domain\SourceReference\Resources\SourceReferenceResource;
use App\Http\Controllers\Controller;
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
}
