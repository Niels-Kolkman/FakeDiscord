<?php

namespace App\Actions\Messages;

use App\Events\ConversationActivity;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Support\DirectMessageNotifier;
use App\Support\MentionNotifier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Store a message in a channel and broadcast it to everyone else viewing
 * the channel. The sender gets the message in the HTTP response, so the
 * broadcast skips their socket (toOthers(), using the X-Socket-ID header).
 *
 * Attachments are written to MessageAttachment::disk() under
 * attachments/{channel}/ with a random name; the original name is only
 * kept in the database. Files go first and the rows in one transaction
 * with the message, so a failure leaves neither a half message nor
 * stray files behind.
 *
 * A reply stores reply_to_id; the payload quotes the original.
 *
 * Direct messages work the same way with a Conversation as the
 * target: the message broadcasts on "conversation.{id}", its files go
 * under attachments/conversations/{id}/, and the conversation's
 * last_message_at moves, so it rises to the top of the DM list. Every
 * participant hears about that through ConversationActivity, and the
 * others get DirectMessageReceived for a browser notification
 * (App\Support\DirectMessageNotifier).
 *
 * In a server channel, the message's mentions (stored on save by
 * App\Support\Mentions) notify the people mentioned
 * (App\Support\MentionNotifier).
 */
class SendMessage
{
    public function __construct(
        private readonly MentionNotifier $mentions,
        private readonly DirectMessageNotifier $directMessages,
    ) {}

    /**
     * @param  list<UploadedFile>  $files  already validated (StoreMessageRequest)
     */
    public function send(User $author, Channel|Conversation $target, string $content, array $files = [], ?Message $replyTo = null): Message
    {
        $disk = MessageAttachment::disk();
        $stored = [];

        try {
            foreach ($files as $file) {
                $stored[] = $this->store($file, $disk, $target);
            }

            $message = DB::transaction(function () use ($author, $target, $content, $stored, $replyTo): Message {
                $message = $target->messages()->create([
                    'user_id' => $author->id,
                    'reply_to_id' => $replyTo?->id,
                    'content' => $content,
                ]);

                $message->setRelation('attachments', $message->attachments()->createMany($stored));

                if ($target instanceof Conversation) {
                    $target->forceFill(['last_message_at' => $message->created_at])->save();
                }

                return $message;
            });
        } catch (Throwable $e) {
            foreach ($stored as $attachment) {
                Storage::disk($disk)->delete($attachment['path']);
            }

            throw $e;
        }

        $message->setRelation('author', $author);
        if ($replyTo !== null) {
            $message->load(Message::replyRelations());
        }
        // A new message has no reactions; saves toPayload() a query.
        $message->setRelation('reactions', $message->reactions()->getRelated()->newCollection());

        // The message is saved either way. If Reverb is down, report it and
        // let the sender carry on; the others see it after a reload.
        rescue(function () use ($message): void {
            broadcast(new MessageSent($message))->toOthers();
        });

        // Not toOthers(): the sender's other tabs re-order their DM list too.
        if ($target instanceof Conversation) {
            rescue(function () use ($target): void {
                broadcast(new ConversationActivity($target));
            });

            // Browser notifications for the other participants.
            $this->directMessages->notify($message, $target);
        } else {
            // Mention notifications for whoever it mentions.
            $this->mentions->sync($message);
        }

        return $message;
    }

    /**
     * @return array{disk: string, path: string, original_name: string, mime_type: string, size: int, metadata: array{width: int, height: int}|null}
     */
    private function store(UploadedFile $file, string $disk, Channel|Conversation $target): array
    {
        // Validated against MessageAttachment::ALLOWED_EXTENSIONS.
        $extension = strtolower($file->getClientOriginalExtension()) ?: ($file->guessExtension() ?? 'bin');
        $directory = $target instanceof Conversation
            ? 'attachments/conversations/'.$target->id
            : 'attachments/'.$target->id;
        $path = $file->storeAs($directory, Str::uuid().'.'.$extension, ['disk' => $disk]);

        if ($path === false) {
            throw new RuntimeException("Could not store attachment on disk [{$disk}].");
        }

        $mime = (string) $file->getMimeType();

        return [
            'disk' => $disk,
            'path' => $path,
            'original_name' => self::displayName($file->getClientOriginalName(), $extension),
            'mime_type' => $mime,
            'size' => (int) $file->getSize(),
            'metadata' => in_array($mime, MessageAttachment::INLINE_IMAGE_TYPES, true)
                ? self::dimensions($file)
                : null,
        ];
    }

    /**
     * The name shown and offered on download: no path, no control
     * characters, at most 255 characters with the extension kept.
     */
    public static function displayName(string $name, string $extension): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', basename(str_replace('\\', '/', $name)));
        $name = trim($name, " .\t");

        if ($name === '') {
            return 'file.'.$extension;
        }

        if (mb_strlen($name) > 255) {
            $suffix = pathinfo($name, PATHINFO_EXTENSION);
            $suffix = $suffix === '' ? '' : '.'.mb_substr($suffix, 0, 16);
            $name = mb_substr($name, 0, 255 - mb_strlen($suffix)).$suffix;
        }

        return $name;
    }

    /**
     * @return array{width: int, height: int}|null
     */
    private static function dimensions(UploadedFile $file): ?array
    {
        $size = @getimagesize($file->getRealPath());

        return $size === false ? null : ['width' => (int) $size[0], 'height' => (int) $size[1]];
    }
}
