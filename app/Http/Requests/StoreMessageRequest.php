<?php

namespace App\Http\Requests;

use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Server;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

/**
 * Post a message in a text channel. Any member may (ServerPolicy::sendMessages).
 *
 * Attachments (ISSUE-19): up to MessageAttachment::MAX_FILES files in
 * `attachments[]` (multipart), each at most MAX_KILOBYTES and one of
 * ALLOWED_EXTENSIONS, by name and by contents. With files the text is
 * optional, like on Discord.
 *
 * Replies (ISSUE-20): `reply_to_id` is the message being answered. It must
 * be a message of this channel that has not been deleted.
 */
class StoreMessageRequest extends FormRequest
{
    /** @var list<string> */
    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    public function authorize(): bool
    {
        $server = $this->route('server');

        return $server instanceof Server
            && ($this->user()?->can('sendMessages', $server) ?? false);
    }

    /**
     * TrimStrings already trims the content, so a whitespace-only message
     * arrives as null and fails "required_without".
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'content' => ['nullable', 'required_without:attachments', 'string', 'max:'.Message::MAX_CONTENT_LENGTH],
            'reply_to_id' => [
                'nullable',
                'integer',
                Rule::exists('messages', 'id')
                    ->where('channel_id', $this->channel()->id)
                    ->whereNull('deleted_at'),
            ],
            'attachments' => ['sometimes', 'array', 'max:'.MessageAttachment::MAX_FILES],
            'attachments.*' => [
                'required',
                'file',
                'extensions:'.implode(',', MessageAttachment::ALLOWED_EXTENSIONS),
                File::types(MessageAttachment::ALLOWED_EXTENSIONS)->max(MessageAttachment::MAX_KILOBYTES),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $megabytes = MessageAttachment::MAX_KILOBYTES / 1024;

        return [
            'content.required_without' => 'Type a message first.',
            'content.max' => 'Messages can be at most :max characters long.',
            'reply_to_id.integer' => 'The message you are replying to does not exist.',
            'reply_to_id.exists' => 'The message you are replying to was deleted.',
            'attachments.array' => 'Attach files to upload them.',
            'attachments.max' => 'You can attach at most :max files to a message.',
            'attachments.*.required' => 'One of the files could not be uploaded.',
            'attachments.*.file' => 'One of the files could not be uploaded.',
            'attachments.*.uploaded' => ':attribute could not be uploaded. Files can be at most '.$megabytes.' MB.',
            'attachments.*.extensions' => ':attribute is not a file type you can upload.',
            'attachments.*.mimes' => ':attribute is not a file type you can upload.',
            'attachments.*.max' => ':attribute is larger than '.$megabytes.' MB.',
        ];
    }

    /**
     * Name the file in errors ("cat.png is larger than 10 MB").
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $names = [];
        foreach ((array) $this->file('attachments', []) as $index => $file) {
            if ($file instanceof UploadedFile) {
                $names["attachments.{$index}"] = $file->getClientOriginalName();
            }
        }

        return $names;
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

                // The rules accept any allowed name with any allowed contents
                // (a text file named .png passes both). A file named as an
                // image must really be one.
                foreach ((array) $this->file('attachments', []) as $index => $file) {
                    $key = "attachments.{$index}";
                    if (! $file instanceof UploadedFile || $validator->errors()->has($key)) {
                        continue;
                    }

                    $extension = strtolower($file->getClientOriginalExtension());
                    if (in_array($extension, self::IMAGE_EXTENSIONS, true)
                        && ! in_array($file->getMimeType(), MessageAttachment::INLINE_IMAGE_TYPES, true)) {
                        $validator->errors()->add($key, $file->getClientOriginalName().' is not a valid image.');
                    }
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

    /**
     * The message being replied to, or NULL. Validated to be a message of
     * this channel.
     */
    public function replyTo(): ?Message
    {
        $id = $this->integer('reply_to_id');

        return $id > 0 ? $this->channel()->messages()->find($id) : null;
    }

    /**
     * The validated files, in the order they were sent.
     *
     * @return list<UploadedFile>
     */
    public function attachments(): array
    {
        return array_values(array_filter(
            (array) $this->file('attachments', []),
            fn (mixed $file): bool => $file instanceof UploadedFile,
        ));
    }
}
