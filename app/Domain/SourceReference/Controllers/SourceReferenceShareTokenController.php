<?php

namespace App\Domain\SourceReference\Controllers;

use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SourceReferenceShareTokenController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->user()->forceFill([
            'share_token' => User::generateShareToken(),
        ])->save();

        return back();
    }
}
