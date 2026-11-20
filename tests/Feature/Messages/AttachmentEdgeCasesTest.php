<?php

namespace Tests\Feature\Messages;

use App\Actions\Servers\CreateServer;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster as BaseBroadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Edge cases on top of AttachmentTest: case of extensions, every
 * inline image type, voice channels, awkward file names in signed URLs,
 * escaping, broadcast failures, edits, cleanup with a broken disk, the TTL
 * floor and eager loading.
 */
class AttachmentEdgeCasesTest extends TestCase
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
    private function postMessage(array $data, ?Channel $channel = null): TestResponse
    {
        return $this->actingAs($this->member)
            ->post($this->messagesUrl($channel), $data, ['Accept' => 'application/json']);
    }

    private function realFile(string $name, string $contents): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * A real image of the given GD type, so its type is sniffed from the bytes.
     */
    private function gdImage(string $name, string $format, int $width = 640, int $height = 480): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        match ($format) {
            'png' => imagepng($image),
            'jpeg' => imagejpeg($image),
            'gif' => imagegif($image),
            'webp' => imagewebp($image),
            default => throw new InvalidArgumentException("Unknown image format [{$format}]."),
        };
        $bytes = (string) ob_get_clean();

        return $this->realFile($name, $bytes);
    }

    private function storedMessage(): Message
    {
        return Message::query()->with('attachments')->latest('id')->firstOrFail();
    }

    private function uploadOne(UploadedFile $file): MessageAttachment
    {
        Event::fake([MessageSent::class]);
        $this->postMessage(['attachments' => [$file]])->assertCreated();

        return $this->storedMessage()->attachments->firstOrFail();
    }

    // --- Validation ------------------------------------------------------

    public function test_upper_case_extensions_are_accepted_and_stored_lower_case(): void
    {
        $attachment = $this->uploadOne($this->gdImage('HOLIDAY.PNG', 'png'));

        $this->assertSame('HOLIDAY.PNG', $attachment->original_name);
        $this->assertStringEndsWith('.png', $attachment->path);
        $this->assertTrue($attachment->isImage());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function inlineImages(): array
    {
        return [
            'png' => ['a.png', 'png', 'image/png'],
            'jpg' => ['a.jpg', 'jpeg', 'image/jpeg'],
            'jpeg' => ['a.jpeg', 'jpeg', 'image/jpeg'],
            'gif' => ['a.gif', 'gif', 'image/gif'],
            'webp' => ['a.webp', 'webp', 'image/webp'],
        ];
    }

    #[DataProvider('inlineImages')]
    public function test_every_inline_image_type_is_previewed_with_its_dimensions(string $name, string $format, string $mime): void
    {
        if (! function_exists('image'.$format)) {
            $this->markTestSkipped("GD cannot write {$format}.");
        }

        $attachment = $this->uploadOne($this->gdImage($name, $format, 640, 480));

        $this->assertSame($mime, $attachment->mime_type);
        $this->assertSame(['width' => 640, 'height' => 480], $attachment->metadata);
        $this->assertSame([400, 300], $attachment->previewSize());

        $response = $this->get($attachment->url())->assertOk();
        $response->assertHeader('Content-Type', $mime);
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_an_image_named_with_another_image_extension_is_still_an_image(): void
    {
        // A JPEG saved as .png: both are images, so it is shown, with its real type.
        $attachment = $this->uploadOne($this->gdImage('photo.png', 'jpeg'));

        $this->assertSame('image/jpeg', $attachment->mime_type);
        $this->get($attachment->url())->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_errors_name_the_offending_file(): void
    {
        $this->postMessage(['content' => 'Hi', 'attachments' => [
            $this->gdImage('fine.png', 'png'),
            $this->realFile('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
            $this->realFile('fake.png', 'not an image at all'),
        ]])
            ->assertUnprocessable()
            ->assertJsonMissingValidationErrors('attachments.0')
            ->assertJsonValidationErrors([
                'attachments.1' => 'logo.svg is not a file type you can upload.',
                'attachments.2' => 'fake.png is not a valid image.',
            ]);

        $this->assertSame(0, Message::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_one_bad_file_rejects_the_whole_message(): void
    {
        $this->postMessage(['content' => 'Hi', 'attachments' => [
            $this->gdImage('a.png', 'png'),
            $this->gdImage('b.png', 'png'),
            UploadedFile::fake()->create('huge.zip', 20 * 1024, 'application/zip'),
        ]])->assertUnprocessable();

        $this->assertSame(0, Message::query()->count());
        $this->assertSame(0, MessageAttachment::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_files_cannot_be_sent_to_a_voice_channel(): void
    {
        $voice = Channel::factory()->voice()->create(['server_id' => $this->server->id, 'name' => 'Lounge']);

        $this->postMessage(['attachments' => [$this->gdImage('a.png', 'png')]], $voice)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content' => 'You cannot send messages in a voice channel.']);

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_text_longer_than_the_limit_is_rejected_even_with_files(): void
    {
        $this->postMessage([
            'content' => str_repeat('a', Message::MAX_CONTENT_LENGTH + 1),
            'attachments' => [$this->gdImage('a.png', 'png')],
        ])->assertUnprocessable()->assertJsonValidationErrors('content');

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    // --- Storing ---------------------------------------------------------

    public function test_stored_names_are_random_and_never_the_original(): void
    {
        Event::fake([MessageSent::class]);
        $this->postMessage(['attachments' => [
            $this->realFile('secret plan.txt', 'one'),
            $this->realFile('secret plan.txt', 'two'),
        ]])->assertCreated();

        [$first, $second] = $this->storedMessage()->attachments->all();

        $this->assertNotSame($first->path, $second->path);
        $this->assertStringNotContainsString('secret', $first->path);
        $this->assertSame('one', Storage::disk('local')->get($first->path));
        $this->assertSame('two', Storage::disk('local')->get($second->path));
        $this->assertSame(['secret plan.txt', 'secret plan.txt'], [$first->original_name, $second->original_name]);
    }

    public function test_a_broadcast_failure_keeps_the_message_and_its_files(): void
    {
        $failing = new class extends BaseBroadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            /**
             * @param  array<int, mixed>  $channels
             * @param  array<string, mixed>  $payload
             */
            public function broadcast(array $channels, $event, array $payload = [])
            {
                throw new BroadcastException('Reverb is not running');
            }
        };
        Broadcast::extend('failing', fn () => $failing);
        config(['broadcasting.connections.failing' => ['driver' => 'failing'], 'broadcasting.default' => 'failing']);

        $this->postMessage(['attachments' => [$this->gdImage('a.png', 'png')]])
            ->assertCreated()
            ->assertJsonCount(1, 'message.attachments');

        $attachment = $this->storedMessage()->attachments->firstOrFail();
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_editing_a_message_cannot_add_files(): void
    {
        Event::fake();
        $this->postMessage(['content' => 'Text only'])->assertCreated();
        $message = $this->storedMessage();

        $this->actingAs($this->member)
            ->patch($this->messagesUrl().'/'.$message->id, [
                'content' => 'Edited',
                'attachments' => [$this->gdImage('sneaky.png', 'png')],
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('message.content', 'Edited')
            ->assertJsonCount(0, 'message.attachments');

        $this->assertSame(0, MessageAttachment::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_editing_keeps_the_existing_files(): void
    {
        Event::fake();
        $attachment = $this->uploadOne($this->gdImage('a.png', 'png'));

        $this->actingAs($this->member)
            ->patchJson($this->messagesUrl().'/'.$attachment->message_id, ['content' => 'Now with a caption'])
            ->assertOk()
            ->assertJsonPath('message.attachments.0.id', $attachment->id);

        Storage::disk('local')->assertExists($attachment->path);
    }

    // --- Serving ---------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function awkwardNames(): array
    {
        return [
            'spaces' => ['my report final.txt'],
            'hash and question mark' => ['what? #1.txt'],
            'percent' => ['100% done.txt'],
            'plus and ampersand' => ['a+b & c.txt'],
            'quotes' => ['the "real" one.txt'],
            'unicode' => ['café 🙂 日本.txt'],
        ];
    }

    #[DataProvider('awkwardNames')]
    public function test_signed_urls_work_with_awkward_file_names(string $name): void
    {
        $attachment = $this->uploadOne($this->realFile($name, 'contents'));
        $this->assertSame($name, $attachment->original_name);

        foreach ([$attachment->url(), $attachment->downloadUrl()] as $url) {
            $response = $this->get($url)->assertOk();
            $this->assertSame('contents', $response->streamedContent());

            $disposition = (string) $response->headers->get('Content-Disposition');
            $this->assertStringStartsWith('attachment;', $disposition);
            $this->assertStringNotContainsString("\n", $disposition);
        }
    }

    public function test_non_ascii_names_get_an_ascii_fallback_and_a_utf8_name(): void
    {
        $attachment = $this->uploadOne($this->realFile('café.txt', 'some plain text'));

        $disposition = (string) $this->get($attachment->downloadUrl())->assertOk()->headers->get('Content-Disposition');

        $this->assertMatchesRegularExpression('/filename="?[\x20-\x7E]+"?/', $disposition);
        $this->assertStringContainsString("filename*=utf-8''caf%C3%A9.txt", $disposition);
    }

    public function test_responses_are_privately_cacheable_only(): void
    {
        $attachment = $this->uploadOne($this->gdImage('a.png', 'png'));

        $cache = (string) $this->get($attachment->url())->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cache);
        $this->assertStringNotContainsString('public', $cache);
    }

    public function test_a_signed_link_works_for_anyone_who_has_it(): void
    {
        // By design (like Discord's CDN): the signature is the access check,
        // so a link pasted elsewhere opens for a non-member until it expires.
        $attachment = $this->uploadOne($this->gdImage('a.png', 'png'));

        $this->actingAs(User::factory()->create())->get($attachment->url())->assertOk();
    }

    public function test_the_ttl_is_never_below_one_minute(): void
    {
        config(['filesystems.attachment_url_ttl' => 0]);
        $attachment = $this->uploadOne($this->gdImage('a.png', 'png'));
        $url = $attachment->url();

        $this->get($url)->assertOk();

        $this->travel(2)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_the_download_flag_cannot_be_changed_without_breaking_the_signature(): void
    {
        $attachment = $this->uploadOne($this->gdImage('a.png', 'png'));

        $this->get(str_replace('download=1', 'download=0', $attachment->downloadUrl()))->assertForbidden();
        $this->get((string) preg_replace('/([?&])download=1&?/', '$1', $attachment->downloadUrl()))->assertForbidden();
        $this->get($attachment->url().'&download=1')->assertForbidden();
    }

    // --- Showing ---------------------------------------------------------

    public function test_file_names_are_escaped_in_the_page(): void
    {
        $this->uploadOne($this->realFile('<img src=x onerror=alert(1)>.txt', 'some plain text'));
        $this->uploadOne($this->gdImage('"><script>alert(1)</script>.png', 'png'));

        $html = (string) $this->actingAs($this->member)
            ->get(route('channels.show', [$this->server, $this->channel]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<img src=x onerror', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;.txt', $html);
    }

    public function test_an_image_without_known_dimensions_has_no_size_attributes(): void
    {
        $message = Message::factory()->inChannel($this->channel)->create(['user_id' => $this->member->id]);
        MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'disk' => 'local',
            'original_name' => 'unknown.png',
            'metadata' => null,
        ]);

        $html = (string) $this->actingAs($this->member)
            ->get(route('channels.show', [$this->server, $this->channel]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<img\s+src="[^"]+"\s+alt="unknown\.png"\s+loading="lazy"\s+class=/', $html);
    }

    public function test_the_history_api_does_not_query_per_attachment(): void
    {
        $seed = function (int $count): void {
            foreach (Message::factory()->count($count)->inChannel($this->channel)->create(['user_id' => $this->member->id]) as $message) {
                MessageAttachment::factory()->count(2)->create(['message_id' => $message->id, 'disk' => 'local']);
            }
        };

        $seed(3);
        $this->coldServerCache();
        DB::enableQueryLog();
        $this->actingAs($this->member)->getJson($this->messagesUrl())->assertOk();
        $few = count(DB::getQueryLog());

        $seed(6);
        $this->coldServerCache();
        DB::flushQueryLog();
        $this->actingAs($this->member)->getJson($this->messagesUrl())
            ->assertOk()
            ->assertJsonCount(9, 'messages');

        $this->assertSame($few, count(DB::getQueryLog()));
    }

    // --- Cleaning up -----------------------------------------------------

    public function test_deleting_a_server_still_works_when_a_disk_is_gone(): void
    {
        $kept = $this->uploadOne($this->gdImage('a.png', 'png'));
        MessageAttachment::factory()->create([
            'message_id' => $kept->message_id,
            'disk' => 'a-disk-that-was-removed-from-config',
        ]);

        $this->actingAs($this->owner)
            ->delete(route('servers.destroy', $this->server), ['confirm_name' => 'Test Server'])
            ->assertRedirect(route('dashboard'));

        $this->assertNull(Server::query()->find($this->server->id));
        // The files on working disks are still removed.
        Storage::disk('local')->assertMissing($kept->path);
    }

    public function test_files_in_no_channels_is_empty_without_a_query(): void
    {
        DB::enableQueryLog();

        $this->assertSame([], MessageAttachment::filesInChannels([]));
        $this->assertSame([], DB::getQueryLog());
    }
}
