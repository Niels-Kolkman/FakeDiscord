<?php

namespace App\Http\Requests;

use App\Models\Server;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rename a server and/or replace or remove its icon.
 */
class UpdateServerRequest extends StoreServerRequest
{
    public function authorize(): bool
    {
        $server = $this->route('server');

        return $server instanceof Server
            && ($this->user()?->can('update', $server) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'remove_icon' => ['sometimes', 'boolean'],
        ];
    }
}
