<?php

namespace App\Domain\Entry\Controllers;

use App\Domain\Entry\Resources\EntryResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
}
