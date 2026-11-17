<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class UpdateProfileRequest extends FormRequest
{
    /** Largest accepted upload, in kilobytes. */
    public const MAX_AVATAR_KB = 4096;

    /** Largest accepted width/height, which keeps GD's memory use bounded. */
    public const MAX_AVATAR_DIMENSION = 4096;

    public const MAX_BIO_LENGTH = 190;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:'.self::MAX_BIO_LENGTH],
            'avatar' => [
                'nullable',
                // SVG is rejected: it can carry scripts and GD cannot rasterise it.
                File::image()
                    ->types(['jpg', 'jpeg', 'png', 'gif', 'webp'])
                    ->max(self::MAX_AVATAR_KB)
                    ->dimensions(Rule::dimensions()
                        ->maxWidth(self::MAX_AVATAR_DIMENSION)
                        ->maxHeight(self::MAX_AVATAR_DIMENSION)),
            ],
            'remove_avatar' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $bio = $this->input('bio');

        $this->merge([
            'name' => is_string($name) ? trim($name) : $name,
            // Normalise Windows line endings so the length limit matches what users see.
            'bio' => is_string($bio) ? str_replace("\r\n", "\n", trim($bio)) : $bio,
        ]);
    }
}
