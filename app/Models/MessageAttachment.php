<?php

namespace App\Models;

use Database\Factories\MessageAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * A file attached to a message (ISSUE-19), stored on `disk` at `path`
 * (App\Actions\Messages\SendMessage). Files are never served from the
 * disk directly: url() and downloadUrl() are temporary signed links to
 * App\Http\Controllers\AttachmentController.
 *
 * `metadata` holds width / height for images.
 */
class MessageAttachment extends Model
{
    /** @use HasFactory<MessageAttachmentFactory> */
    use HasFactory;

    /** At most this many files per message. */
    public const MAX_FILES = 5;

    /** Largest file, in kilobytes (Laravel's "max" rule for files). */
    public const MAX_KILOBYTES = 10 * 1024;

    /**
     * Extensions that may be uploaded. The "extensions" rule checks the
     * file name and File::types() the type guessed from the contents, so
     * both have to match. No SVG or HTML: they could run scripts.
     *
     * @var list<string>
     */
    public const ALLOWED_EXTENSIONS = [
        // Images (shown inline)
        'png', 'jpg', 'jpeg', 'gif', 'webp',
        // Documents
        'pdf', 'txt', 'md', 'csv', 'json', 'log',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
        // Archives
        'zip', '7z', 'gz', 'tar',
        // Audio and video
        'mp3', 'ogg', 'wav', 'mp4', 'webm', 'mov',
    ];

    /**
     * Only these are previewed inline and served with Content-Disposition:
     * inline. Everything else is always a download.
     *
     * @var list<string>
     */
    public const INLINE_IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** Largest inline preview, in CSS pixels. */
    public const PREVIEW_WIDTH = 400;

    public const PREVIEW_HEIGHT = 300;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'message_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * The disk new attachments are stored on.
     */
    public static function disk(): string
    {
        return config()->string('filesystems.attachment_disk', 'local');
    }

    /**
     * The stored files of every attachment in the given channels, deleted
     * messages included. Deleting a channel or server removes the rows
     * through ON DELETE CASCADE, so read these first and pass them to
     * deleteFiles() once the delete has been committed.
     *
     * @param  list<int>  $channelIds
     * @return list<array{disk: string, path: string}>
     */
    public static function filesInChannels(array $channelIds): array
    {
        if ($channelIds === []) {
            return [];
        }

        return self::query()
            ->whereIn('message_id', Message::withTrashed()->whereIn('channel_id', $channelIds)->select('id'))
            ->get(['disk', 'path'])
            ->map(fn (self $attachment): array => ['disk' => $attachment->disk, 'path' => $attachment->path])
            ->all();
    }

    /**
     * The same for one conversation (ISSUE-24: a group DM is deleted when
     * its last participant leaves).
     *
     * @return list<array{disk: string, path: string}>
     */
    public static function filesInConversation(int $conversationId): array
    {
        return self::query()
            ->whereIn('message_id', Message::withTrashed()->where('conversation_id', $conversationId)->select('id'))
            ->get(['disk', 'path'])
            ->map(fn (self $attachment): array => ['disk' => $attachment->disk, 'path' => $attachment->path])
            ->all();
    }

    /**
     * @param  list<array{disk: string, path: string}>  $files
     */
    public static function deleteFiles(array $files): void
    {
        foreach (collect($files)->groupBy('disk') as $disk => $group) {
            rescue(fn () => Storage::disk((string) $disk)->delete($group->pluck('path')->all()));
        }
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function isImage(): bool
    {
        return in_array($this->mime_type, self::INLINE_IMAGE_TYPES, true);
    }

    public function width(): ?int
    {
        $width = $this->metadata['width'] ?? null;

        return is_int($width) ? $width : null;
    }

    public function height(): ?int
    {
        $height = $this->metadata['height'] ?? null;

        return is_int($height) ? $height : null;
    }

    /**
     * Size of the inline preview: the image scaled down (never up) to fit
     * PREVIEW_WIDTH x PREVIEW_HEIGHT, so the page reserves the space before
     * it loads and the scroll position does not jump. NULL without known
     * dimensions. channel-messages.js has the same previewSize().
     *
     * @return array{int, int}|null [width, height]
     */
    public function previewSize(): ?array
    {
        $width = $this->width();
        $height = $this->height();
        if ($width === null || $height === null || $width < 1 || $height < 1) {
            return null;
        }

        $scale = min(1, self::PREVIEW_WIDTH / $width, self::PREVIEW_HEIGHT / $height);

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    /**
     * Signed link that shows an image inline (other files download).
     */
    public function url(): string
    {
        return $this->signedUrl(false);
    }

    /**
     * Signed link that always downloads the file.
     */
    public function downloadUrl(): string
    {
        return $this->signedUrl(true);
    }

    /**
     * "1.5 MB", for the file card.
     */
    public function humanSize(): string
    {
        return self::formatSize($this->size);
    }

    public static function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $size = $bytes / 1024;
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return rtrim(rtrim(number_format($size, 1, '.', ''), '0'), '.').' '.$units[$unit];
    }

    /**
     * The JSON for an attachment, part of Message::toPayload().
     *
     * @return array{id: int, name: string, size: int, mime_type: string, is_image: bool, width: int|null, height: int|null, url: string, download_url: string}
     */
    public function toPayload(): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->original_name,
            'size' => $this->size,
            'mime_type' => $this->mime_type,
            'is_image' => $this->isImage(),
            'width' => $this->width(),
            'height' => $this->height(),
            'url' => $this->url(),
            'download_url' => $this->downloadUrl(),
        ];
    }

    private function signedUrl(bool $download): string
    {
        $parameters = ['id' => $this->id, 'filename' => $this->original_name];
        if ($download) {
            $parameters['download'] = 1;
        }

        return URL::temporarySignedRoute(
            'attachments.show',
            now()->addMinutes(max(1, config()->integer('filesystems.attachment_url_ttl', 60 * 24))),
            $parameters,
        );
    }
}
