<?php

namespace App\Http\Requests;

use App\Models\ChannelCategory;
use App\Models\Server;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create or rename a channel category.
 */
class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $server = $this->route('server');

        return $server instanceof Server
            && ($this->user()?->can('manageChannels', $server) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.ChannelCategory::MAX_NAME_LENGTH],
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        $this->merge([
            'name' => is_string($name) ? (string) preg_replace('/\s+/u', ' ', trim($name)) : $name,
        ]);
    }
}
