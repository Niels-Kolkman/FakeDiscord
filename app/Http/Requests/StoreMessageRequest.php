<?php

namespace App\Http\Requests;

use App\Models\Channel;
use App\Models\Message;
use App\Models\Server;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Post a message in a text channel. Any member may (ServerPolicy::sendMessages).
 */
class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $server = $this->route('server');

        return $server instanceof Server
            && ($this->user()?->can('sendMessages', $server) ?? false);
    }

    /**
     * TrimStrings already trims the content, so a whitespace-only message
     * arrives as null and fails "required".
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:'.Message::MAX_CONTENT_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.required' => 'Type a message first.',
            'content.max' => 'Messages can be at most :max characters long.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->channel()->isVoice()) {
                    $validator->errors()->add('content', 'You cannot send messages in a voice channel.');
                }
            },
        ];
    }

    public function channel(): Channel
    {
        $channel = $this->route('channel');
        abort_unless($channel instanceof Channel, 404);

        return $channel;
    }

    public function content(): string
    {
        return $this->string('content')->value();
    }
}
