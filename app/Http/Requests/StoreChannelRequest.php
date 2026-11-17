<?php

namespace App\Http\Requests;

use App\Models\Channel;
use App\Models\Server;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a text channel: name, optional topic and optional category.
 */
class StoreChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageChannels', $this->targetServer()) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.Channel::MAX_NAME_LENGTH],
            'topic' => ['nullable', 'string', 'max:'.Channel::MAX_TOPIC_LENGTH],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('channel_categories', 'id')->where('server_id', $this->targetServer()->getKey()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter a channel name with at least one letter or number.',
            'category_id.exists' => 'That category does not exist in this server.',
        ];
    }

    /**
     * The {server} route parameter (Request::server() is the $_SERVER accessor).
     */
    public function targetServer(): Server
    {
        $server = $this->route('server');
        abort_unless($server instanceof Server, 404);

        return $server;
    }

    public function categoryId(): ?int
    {
        return $this->filled('category_id') ? $this->integer('category_id') : null;
    }

    public function topic(): ?string
    {
        return $this->filled('topic') ? $this->string('topic')->value() : null;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        if (is_string($name)) {
            $this->merge(['name' => $this->normalizeName($name)]);
        }
    }

    protected function normalizeName(string $name): string
    {
        return Channel::normalizeName($name);
    }
}
