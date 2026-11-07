<?php

namespace App\Domain\SourceReference\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Inertia\Inertia;

class BookmarkletSourceReferenceRequest extends CheckSourceReferenceRequest
{
    public function authorize(): bool
    {
        // Only the captured URL is variable; the signature binds the endpoint and owner.
        return (string) $this->user()->id === $this->route('user')
            && $this->hasValidSignatureWhileIgnoring(['url']);
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            Inertia::render('source-references/bookmarklet', [
                'captureUrl' => null,
                'errorMessage' => $validator->errors()->first('url'),
            ])->toResponse($this)->setStatusCode(422)->withHeaders([
                'Cache-Control' => 'no-store, private',
                'Referrer-Policy' => 'no-referrer',
            ]),
        );
    }
}
