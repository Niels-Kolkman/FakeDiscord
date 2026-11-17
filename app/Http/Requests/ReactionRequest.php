<?php

namespace App\Http\Requests;

use App\Models\Message;
use App\Support\Reactions;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add (POST) or remove (DELETE) a reaction, field `emoji`. Any member of
 * the server may react (MessagePolicy::react).
 *
 * Adding only accepts a single unicode emoji (Reactions::isValidEmoji()).
 * Removing accepts any stored value, so reactions from older data (such as
 * the demo seeder's custom ":partyparrot:") can still be taken back.
 */
class ReactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('react', $this->message()) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = ['required', 'string', 'max:64'];

        if ($this->isMethod('POST')) {
            $rules[] = function (string $attribute, mixed $value, Closure $fail): void {
                if (! Reactions::isValidEmoji($value)) {
                    $fail(__('Choose a single emoji.'));
                }
            };
        }

        return ['emoji' => $rules];
    }

    public function message(): Message
    {
        $message = $this->route('message');
        abort_unless($message instanceof Message, 404);

        return $message;
    }

    public function emoji(): string
    {
        return $this->string('emoji')->value();
    }
}
