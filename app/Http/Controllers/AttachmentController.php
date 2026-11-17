<?php

namespace App\Http\Controllers;

use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves message attachments (ISSUE-19) through temporary signed URLs
 * (MessageAttachment::url() / downloadUrl()), like Discord's CDN links:
 * whoever has an unexpired link can open the file, without a session, so
 * it also works in <img> tags and download managers. The `signed`
 * middleware rejects altered or expired links with a 403.
 *
 * Attachments of deleted messages are a 404. Only PNG, JPEG, GIF and WebP
 * are shown inline; every other type is always sent as a download, with
 * nosniff and a sandbox CSP, so an uploaded file can never run as a page
 * on this origin.
 */
class AttachmentController extends Controller
{
    public function show(Request $request, string $id): StreamedResponse
    {
        // Looked up here, after `signed` has checked the link.
        $attachment = MessageAttachment::query()->find((int) $id);

        // The relation hides soft deleted messages.
        abort_if($attachment === null || $attachment->message === null, 404);

        $storage = Storage::disk($attachment->disk);
        abort_unless($storage->exists($attachment->path), 404);

        $inline = $attachment->isImage() && ! $request->boolean('download');

        $headers = [
            'Content-Type' => $inline ? $attachment->mime_type : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
            'Cache-Control' => 'private, max-age=3600',
        ];

        return $storage->response(
            $attachment->path,
            $attachment->original_name,
            $headers,
            $inline ? 'inline' : 'attachment',
        );
    }
}
