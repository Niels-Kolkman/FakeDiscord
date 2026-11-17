<?php

namespace App\Http\Requests;

use App\Models\Channel;
use App\Models\ChannelCategory;
use App\Models\Server;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The full sidebar order sent after a drag and drop:
 *
 *     {"uncategorized": [1, 2], "categories": [{"id": 3, "channels": [4, 5]}]}
 *
 * This only checks the shape. App\Actions\Channels\ReorderChannels checks
 * that the ids are exactly the server's channels and categories.
 */
class ReorderChannelsRequest extends FormRequest
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
            'uncategorized' => ['present', 'array', 'max:'.Channel::MAX_PER_SERVER],
            'uncategorized.*' => ['integer'],
            'categories' => ['present', 'array', 'max:'.ChannelCategory::MAX_PER_SERVER],
            'categories.*' => ['array:id,channels'],
            'categories.*.id' => ['required', 'integer'],
            'categories.*.channels' => ['present', 'array', 'max:'.Channel::MAX_PER_SERVER],
            'categories.*.channels.*' => ['integer'],
        ];
    }

    /**
     * @return list<int>
     */
    public function uncategorized(): array
    {
        return array_values(array_map('intval', (array) $this->input('uncategorized', [])));
    }

    /**
     * @return list<array{id: int, channels: list<int>}>
     */
    public function categories(): array
    {
        return array_values(array_map(fn (array $category): array => [
            'id' => (int) $category['id'],
            'channels' => array_values(array_map('intval', (array) $category['channels'])),
        ], (array) $this->input('categories', [])));
    }
}
