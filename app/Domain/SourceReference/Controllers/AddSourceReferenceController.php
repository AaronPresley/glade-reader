<?php

namespace App\Domain\SourceReference\Controllers;

use App\Domain\SourceReference\Actions\CheckSourceReference;
use App\Domain\SourceReference\Requests\AddSourceReferenceRequest;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class AddSourceReferenceController extends Controller
{
    public function __invoke(
        AddSourceReferenceRequest $request,
        CheckSourceReference $checkSourceReference,
        string $token,
    ): JsonResponse|Response {
        $user = User::query()
            ->where('share_token', $token)
            ->first();

        if (! $user instanceof User) {
            return response()->json([
                'message' => 'Invalid share token.',
            ], 404);
        }

        $checkSourceReference->handle(
            user: $user,
            url: $request->validated('url'),
        );

        return response()->noContent();
    }
}
