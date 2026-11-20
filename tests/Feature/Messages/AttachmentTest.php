<?php

namespace Tests\Feature\Messages;

use App\Actions\Messages\SendMessage;
use App\Actions\Servers\CreateServer;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Up to 5 files per message (10 MB each, allowlisted types),
 * stored on the configured disk and served through temporary signed URLs.
 */
class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private Server $server;

    private Channel $channel;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Event::fake([MessageSent::class]);

        $this->owner = User::factory()->create(['name' => 'Owner']);
        $this->member = User::factory()->create(['name' => 'Member']);
        $this->server = app(CreateServer::class)->create($this->owner, 'Test Server');
        ServerMember::factory()->create(['server_id' => $this->server->id, 'user_id' => $this->member->id]);

        /** @var Channel $general */
        $general = $this->server->channels()->firstOrFail();
        $this->channel = $general;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function messagesUrl(?Channel $channel = null): string
    {
        $channel ??= $this->channel;

        return "/servers/{$channel->server_id}/channels/{$channel->id}/messages";
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function postMessage(array $data, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->member)
            ->post($this->messagesUrl(), $data, ['Accept' => 'application/json']);
    }

    /**
     * A real file whose type is sniffed from its contents (fake files
     * report the type of their name).
     */
    private function realFile(string $name, string $contents): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function pngFile(string $name = 'cat.png', int $width = 800, int $height = 600): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    private function storedMessage(): Message
    {
        return Message::query()->with('attachments')->latest('id')->firstOrFail();
    }

    // --- Uploading ------------------------------------------------------

    public function test_a_member_can_attach_an_image_and_a_document(): void
    {
        $response = $this->postMessage([
            'content' => 'Look at these',
            'attachments' => [
                $this->pngFile('cat.png', 800, 600),
                UploadedFile::fake()->create('notes.pdf', 120, 'application/pdf'),
            ],
        ])->assertCreated();

        $message = $this->storedMessage();
        $this->assertCount(2, $message->attachments);

        [$image, $pdf] = $message->attachments->all();
        $this->assertSame('local', $image->disk);
        $this->assertSame('cat.png', $image->original_name);
        $this->assertSame('image/png', $image->mime_type);
        $this->assertSame(['width' => 800, 'height' => 600], $image->metadata);
        $this->assertMatchesRegularExpression('#^attachments/'.$this->channel->id.'/[0-9a-f-]{36}\.png$#', $image->path);
        Storage::disk('local')->assertExists($image->path);

        $this->assertSame('notes.pdf', $pdf->original_name);
        $this->assertSame('application/pdf', $pdf->mime_type);
        $this->assertSame(120 * 1024, $pdf->size);
        $this->assertNull($pdf->metadata);
        Storage::disk('local')->assertExists($pdf->path);

        $attachments = $response->json('message.attachments');
        $this->assertCount(2, $attachments);
        $this->assertSame(
            ['id', 'name', 'size', 'mime_type', 'is_image', 'width', 'height', 'url', 'download_url'],
            array_keys($attachments[0]),
        );
        $this->assertSame('cat.png', $attachments[0]['name']);
        $this->assertTrue($attachments[0]['is_image']);
        $this->assertSame(800, $attachments[0]['width']);
        $this->assertFalse($attachments[1]['is_image']);
        $this->assertNull($attachments[1]['width']);
        $this->assertStringContainsString('signature=', $attachments[0]['url']);
        $this->assertStringContainsString('expires=', $attachments[0]['url']);
        $this->assertStringContainsString('download=1', $attachments[0]['download_url']);
    }

    public function test_the_broadcast_carries_the_attachments(): void
    {
        $this->postMessage(['content' => 'Hi', 'attachments' => [$this->pngFile()]])->assertCreated();

        Event::assertDispatched(MessageSent::class, function (MessageSent $event): bool {
            $attachments = $event->broadcastWith()['attachments'];

            return count($attachments) === 1 && $attachments[0]['name'] === 'cat.png';
        });
    }

    public function test_files_can_be_sent_without_text(): void
    {
        $this->postMessage(['attachments' => [$this->pngFile()]])
            ->assertCreated()
            ->assertJsonPath('message.content', '');

        $this->assertSame('', $this->storedMessage()->content);
    }

    public function test_a_message_needs_text_or_files(): void
    {
        $this->postMessage(['content' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content' => 'Type a message first.']);

        $this->postMessage(['content' => '', 'attachments' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content');
    }

    public function test_at_most_five_files(): void
    {
        $files = array_map(fn (int $i): UploadedFile => UploadedFile::fake()->create("f{$i}.txt", 1, 'text/plain'), range(1, 6));

        $this->postMessage(['content' => 'Too many', 'attachments' => $files])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachments' => 'You can attach at most 5 files to a message.']);

        $this->assertSame(0, Message::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_exactly_five_files_are_fine(): void
    {
        $files = array_map(fn (int $i): UploadedFile => UploadedFile::fake()->create("f{$i}.txt", 1, 'text/plain'), range(1, 5));

        $this->postMessage(['attachments' => $files])->assertCreated();

        $this->assertCount(5, $this->storedMessage()->attachments);
        $this->assertSame(['f1.txt', 'f2.txt', 'f3.txt', 'f4.txt', 'f5.txt'], $this->storedMessage()->attachments->pluck('original_name')->all());
    }

    public function test_files_over_ten_megabytes_are_rejected(): void
    {
        $this->postMessage(['attachments' => [
            UploadedFile::fake()->create('small.pdf', 10 * 1024, 'application/pdf'),
            UploadedFile::fake()->create('huge.pdf', 10 * 1024 + 1, 'application/pdf'),
        ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachments.1' => 'huge.pdf is larger than 10 MB.'])
            ->assertJsonMissingValidationErrors('attachments.0');

        $this->assertSame(0, Message::query()->count());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedFiles(): array
    {
        return [
            'svg (scripts)' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'html' => ['page.html', '<!doctype html><html><body><script>alert(1)</script></body></html>'],
            'executable' => ['setup.exe', "MZ\x90\x00".str_repeat("\x00", 200)],
            'html renamed to txt' => ['notes.txt', '<!doctype html><html><head><title>x</title></head><body><script>alert(1)</script></body></html>'],
            'text named as an image' => ['evil.png', 'just some text, not an image'],
            'no extension' => ['README', 'plain text'],
        ];
    }

    #[DataProvider('rejectedFiles')]
    public function test_disallowed_types_are_rejected(string $name, string $contents): void
    {
        $this->postMessage(['content' => 'Hi', 'attachments' => [$this->realFile($name, $contents)]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachments.0');

        $this->assertSame(0, Message::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_real_text_file_is_accepted_by_its_contents(): void
    {
        $this->postMessage(['attachments' => [$this->realFile('notes.txt', "line one\nline two\n")]])->assertCreated();

        $attachment = $this->storedMessage()->attachments->firstOrFail();
        $this->assertSame('text/plain', $attachment->mime_type);
        $this->assertFalse($attachment->isImage());
    }

    public function test_attachments_must_be_files(): void
    {
        $this->postMessage(['content' => 'Hi', 'attachments' => ['not a file']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachments.0');

        $this->postMessage(['content' => 'Hi', 'attachments' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachments');
    }

    public function test_files_go_to_the_configured_disk(): void
    {
        Storage::fake('uploads');
        config(['filesystems.attachment_disk' => 'uploads']);

        $this->postMessage(['attachments' => [$this->pngFile()]])->assertCreated();

        $attachment = $this->storedMessage()->attachments->firstOrFail();
        $this->assertSame('uploads', $attachment->disk);
        Storage::disk('uploads')->assertExists($attachment->path);
        $this->assertSame([], Storage::disk('local')->allFiles());

        // Served from the disk it was stored on, also after the default changes.
        config(['filesystems.attachment_disk' => 'local']);
        $this->get($attachment->url())->assertOk();
    }

    public function test_stored_files_are_removed_when_saving_the_message_fails(): void
    {
        MessageAttachment::creating(function (): void {
            throw new RuntimeException('database is down');
        });

        $this->withoutExceptionHandling();

        try {
            $this->postMessage(['content' => 'Hi', 'attachments' => [$this->pngFile(), $this->pngFile('dog.png')]]);
            $this->fail('The exception should have been rethrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('database is down', $e->getMessage());
        }

        $this->assertSame(0, Message::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_non_members_cannot_upload(): void
    {
        $outsider = User::factory()->create();

        $this->postMessage(['attachments' => [$this->pngFile()]], $outsider)->assertForbidden();

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_form_works_without_javascript(): void
    {
        $this->actingAs($this->member)
            ->post($this->messagesUrl(), ['content' => 'No JS', 'attachments' => [$this->pngFile()]])
            ->assertRedirect(route('channels.show', [$this->server, $this->channel]));

        $this->assertCount(1, $this->storedMessage()->attachments);

        $this->actingAs($this->member)
            ->from(route('channels.show', [$this->server, $this->channel]))
            ->post($this->messagesUrl(), ['attachments' => [$this->realFile('x.svg', '<svg/>')]])
            ->assertRedirect(route('channels.show', [$this->server, $this->channel]))
            ->assertSessionHasErrors('attachments.0');
    }

    public function test_file_names_are_cleaned_up(): void
    {
        $this->assertSame('passwd', SendMessage::displayName('../../etc/passwd', 'txt'));
        $this->assertSame('evil.txt', SendMessage::displayName('C:\\Users\\me\\evil.txt', 'txt'));
        $this->assertSame('ab.txt', SendMessage::displayName("a\x00b\n.txt", 'txt'));
        $this->assertSame('file.png', SendMessage::displayName('...', 'png'));
        $this->assertSame('foto 🙂.jpg', SendMessage::displayName('foto 🙂.jpg', 'jpg'));

        $long = SendMessage::displayName(str_repeat('a', 300).'.pdf', 'pdf');
        $this->assertSame(255, mb_strlen($long));
        $this->assertStringEndsWith('.pdf', $long);
    }

    // --- Serving -------------------------------------------------------

    private function uploadOne(UploadedFile $file): MessageAttachment
    {
        $this->postMessage(['attachments' => [$file]])->assertCreated();

        return $this->storedMessage()->attachments->firstOrFail();
    }

    public function test_images_are_shown_inline_through_a_signed_url(): void
    {
        $attachment = $this->uploadOne($this->pngFile());
        $contents = Storage::disk('local')->get($attachment->path);

        // No session needed: the signature is the access check.
        auth()->logout();
        $response = $this->get($attachment->url())->assertOk();

        $this->assertSame($contents, $response->streamedContent());
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('cat.png', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_the_download_url_always_downloads(): void
    {
        $attachment = $this->uploadOne($this->pngFile());

        $response = $this->get($attachment->downloadUrl())->assertOk();

        $response->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_other_files_are_never_shown_inline(): void
    {
        $attachment = $this->uploadOne($this->realFile('notes.txt', 'hello'));

        $response = $this->get($attachment->url())->assertOk();

        $response->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('hello', $response->streamedContent());
    }

    public function test_unsigned_or_tampered_urls_are_forbidden(): void
    {
        $attachment = $this->uploadOne($this->pngFile());
        $url = $attachment->url();

        $this->get(route('attachments.show', ['id' => $attachment->id, 'filename' => 'cat.png']))->assertForbidden();
        $this->get((string) preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $url))->assertForbidden();
        // Another (existing or not) id with this signature: 403, never 404.
        $this->get(str_replace('/attachments/'.$attachment->id.'/', '/attachments/'.($attachment->id + 1).'/', $url))->assertForbidden();
        $this->get(str_replace('/attachments/'.$attachment->id.'/', '/attachments/999999/', $url))->assertForbidden();
        $this->get(str_replace('cat.png', 'other.png', $url))->assertForbidden();
    }

    public function test_signed_urls_expire(): void
    {
        config(['filesystems.attachment_url_ttl' => 60]);
        $attachment = $this->uploadOne($this->pngFile());
        $url = $attachment->url();

        $this->travel(59)->minutes();
        $this->get($url)->assertOk();

        $this->travel(2)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_attachments_of_deleted_messages_are_gone(): void
    {
        $attachment = $this->uploadOne($this->pngFile());
        $url = $attachment->url();

        $this->actingAs($this->member)
            ->deleteJson($this->messagesUrl().'/'.$attachment->message_id)
            ->assertNoContent();

        $this->get($url)->assertNotFound();
    }

    public function test_a_missing_file_is_a_404(): void
    {
        $attachment = $this->uploadOne($this->pngFile());
        Storage::disk('local')->delete($attachment->path);

        $this->get($attachment->url())->assertNotFound();
    }

    // --- Showing --------------------------------------------------------

    public function test_the_channel_page_shows_previews_and_file_cards(): void
    {
        $this->postMessage(['content' => 'Files', 'attachments' => [
            $this->pngFile('wide.png', 1600, 600),
            UploadedFile::fake()->create('report.pdf', 1536, 'application/pdf'),
        ]])->assertCreated();

        $response = $this->actingAs($this->member)
            ->get(route('channels.show', [$this->server, $this->channel]))
            ->assertOk();

        $response->assertSee('data-message-attachments', false);
        $response->assertSee('alt="wide.png"', false);
        // 1600x600 scaled to fit 400x300.
        $response->assertSee('width="400" height="150"', false);
        $response->assertSee('report.pdf');
        $response->assertSee('1.5 MB');
        $response->assertSee('/attachments/', false);
        $response->assertSee('signature=', false);
        // The composer can upload.
        $response->assertSee('enctype="multipart/form-data"', false);
        $response->assertSee('name="attachments[]"', false);
        $response->assertSee('data-max-files="5"', false);
        $response->assertSee('data-max-file-bytes="'.(10 * 1024 * 1024).'"', false);
    }

    public function test_the_history_api_includes_attachments(): void
    {
        $this->postMessage(['attachments' => [$this->pngFile()]])->assertCreated();

        $this->actingAs($this->member)
            ->getJson($this->messagesUrl())
            ->assertOk()
            ->assertJsonPath('messages.0.attachments.0.name', 'cat.png')
            ->assertJsonPath('messages.0.attachments.0.is_image', true);
    }

    public function test_a_message_with_files_may_lose_its_text_when_edited(): void
    {
        $this->postMessage(['content' => 'Caption', 'attachments' => [$this->pngFile()]])->assertCreated();
        $withFiles = $this->storedMessage();

        $this->actingAs($this->member)
            ->patchJson($this->messagesUrl().'/'.$withFiles->id, ['content' => ''])
            ->assertOk()
            ->assertJsonPath('message.content', '')
            ->assertJsonCount(1, 'message.attachments');

        $this->postMessage(['content' => 'Only text'])->assertCreated();
        $textOnly = $this->storedMessage();

        $this->actingAs($this->member)
            ->patchJson($this->messagesUrl().'/'.$textOnly->id, ['content' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content' => 'A message cannot be empty. Delete it instead.']);
    }

    // --- Cleaning up ----------------------------------------------------

    public function test_deleting_a_channel_removes_its_files(): void
    {
        $other = Channel::factory()->create(['server_id' => $this->server->id, 'name' => 'other']);
        $keep = $this->uploadOne($this->pngFile());

        $this->actingAs($this->member)
            ->post($this->messagesUrl($other), ['attachments' => [$this->pngFile('gone.png')]], ['Accept' => 'application/json'])
            ->assertCreated();
        $gone = $this->storedMessage()->attachments->firstOrFail();

        // Soft deleted messages keep their file until the channel goes.
        $this->actingAs($this->member)->deleteJson($this->messagesUrl($other).'/'.$gone->message_id)->assertNoContent();
        Storage::disk('local')->assertExists($gone->path);

        $this->actingAs($this->owner)
            ->delete(route('channels.destroy', [$this->server, $other]))
            ->assertRedirect();

        Storage::disk('local')->assertMissing($gone->path);
        Storage::disk('local')->assertExists($keep->path);
    }

    public function test_deleting_a_server_removes_its_files(): void
    {
        $attachment = $this->uploadOne($this->pngFile());

        $this->actingAs($this->owner)
            ->delete(route('servers.destroy', $this->server), ['confirm_name' => 'Test Server'])
            ->assertRedirect(route('dashboard'));

        Storage::disk('local')->assertMissing($attachment->path);
        $this->assertSame(0, MessageAttachment::query()->count());
    }

    // --- Helpers --------------------------------------------------------

    public function test_sizes_are_formatted_like_the_script(): void
    {
        $this->assertSame('0 B', MessageAttachment::formatSize(0));
        $this->assertSame('1023 B', MessageAttachment::formatSize(1023));
        $this->assertSame('1 KB', MessageAttachment::formatSize(1024));
        $this->assertSame('1.5 MB', MessageAttachment::formatSize(1536 * 1024));
        $this->assertSame('10 MB', MessageAttachment::formatSize(10 * 1024 * 1024));
    }

    public function test_preview_sizes_fit_without_upscaling(): void
    {
        $size = fn (?array $metadata): ?array => MessageAttachment::factory()->make(['metadata' => $metadata])->previewSize();

        $this->assertSame([400, 150], $size(['width' => 1600, 'height' => 600]));
        $this->assertSame([200, 300], $size(['width' => 1000, 'height' => 1500]));
        $this->assertSame([50, 40], $size(['width' => 50, 'height' => 40]));
        $this->assertNull($size(null));
        $this->assertNull($size(['width' => 0, 'height' => 10]));
    }
}
