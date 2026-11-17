<?php

namespace App\Http\Requests;

use App\Models\Message;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit a message. Only its author may (MessagePolicy::update). The content
 * rules are the same as for sending (StoreMessageRequest).
 */
class UpdateMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->message()) ?? false;
    }

    /**
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
            'content.required' => 'A message cannot be empty. Delete it instead.',
            'content.max' => 'Messages can be at most :max characters long.',
        ];
    }

    public function message(): Message
    {
        $message = $this->route('message');
        abort_unless($message instanceof Message, 404);

        return $message;
    }

    public function content(): string
    {
        return $this->string('content')->value();
    }
}
