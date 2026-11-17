<?php

namespace App\Http\Requests;

use App\Models\Server;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreServerRequest extends FormRequest
{
    public const MIN_NAME_LENGTH = 2;

    /** Matches the servers.name column. */
    public const MAX_NAME_LENGTH = 100;

    /** Largest accepted icon upload, in kilobytes. */
    public const MAX_ICON_KB = 4096;

    /** Largest accepted width/height, which keeps GD's memory use bounded. */
    public const MAX_ICON_DIMENSION = 4096;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Server::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:'.self::MIN_NAME_LENGTH, 'max:'.self::MAX_NAME_LENGTH],
            'icon' => self::iconRules(),
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function iconRules(): array
    {
        return [
            'nullable',
            // SVG is rejected: it can carry scripts and GD cannot rasterise it.
            File::image()
                ->types(['jpg', 'jpeg', 'png', 'gif', 'webp'])
                ->max(self::MAX_ICON_KB)
                ->dimensions(Rule::dimensions()
                    ->maxWidth(self::MAX_ICON_DIMENSION)
                    ->maxHeight(self::MAX_ICON_DIMENSION)),
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        $this->merge([
            // Collapse runs of whitespace so "My   Server" and "My Server" look the same.
            'name' => is_string($name) ? (string) preg_replace('/\s+/u', ' ', trim($name)) : $name,
        ]);
    }
}
