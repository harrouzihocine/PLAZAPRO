<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Http\Controllers;

use App\Modules\Collaboration\Models\MessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams a chat attachment (image / voice note / file) inline, only to a
 * participant of the owning conversation. Files live on the private `chat` disk
 * and are never exposed by a public URL. Mirrors MediaController's streaming:
 * local disks stream (byte-range for audio seeking); remote disks redirect to a
 * short-lived signed URL.
 */
class AttachmentController extends Controller
{
    public function show(Request $request, MessageAttachment $attachment): Response
    {
        $message = $attachment->message()->with('conversation')->first();

        abort_if($message === null || $message->conversation === null, 404);
        abort_unless($message->conversation->hasParticipant($request->user()), 403);

        return $this->stream($attachment->disk, $attachment->path, $attachment->mime_type, basename($attachment->path));
    }

    private function stream(string $disk, string $path, string $mime, string $name): Response
    {
        $storage = Storage::disk($disk);
        abort_unless($storage->exists($path), 404);

        if (config("filesystems.disks.{$disk}.driver") === 'local') {
            return response()->file($storage->path($path), [
                'Content-Type' => $mime,
                'Content-Disposition' => 'inline; filename="'.addslashes($name).'"',
            ]);
        }

        return redirect($storage->temporaryUrl($path, now()->addMinutes(5)));
    }
}
