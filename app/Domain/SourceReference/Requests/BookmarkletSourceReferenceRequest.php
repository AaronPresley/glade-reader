<?php

namespace App\Domain\SourceReference\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Inertia\Inertia;

class BookmarkletSourceReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only the captured URL is variable; the signature binds the endpoint and owner.
        return (string) $this->user()->id === $this->route('user')
            && $this->hasValidSignatureWhileIgnoring(['url']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'url:http,https', 'max:2048'],
        ];
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
