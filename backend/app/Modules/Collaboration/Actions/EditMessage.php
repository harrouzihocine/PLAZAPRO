<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Actions;

use App\Modules\Collaboration\Enums\MessageType;
use App\Modules\Collaboration\Events\MessageUpdated;
use App\Modules\Collaboration\Models\Message;

/**
 * Edit a text message in place (Messenger-style). Only the author, only plain
 * text, only while the message is still active — a redacted message stays
 * deleted. Stamps edited_at so every client shows the "(edited)" marker, and
 * broadcasts MessageUpdated so open threads patch the bubble live.
 * Authorisation (participant scope + authorship) is the controller's job.
 */
class EditMessage
{
    public function handle(Message $message, string $body): Message
    {
        abort_if($message->isCancelled(), 422, 'A deleted message cannot be edited.');
        abort_unless($message->type === MessageType::Text, 422, 'Only text messages can be edited.');

        $message->forceFill(['body' => $body, 'edited_at' => now()])->save();

        MessageUpdated::dispatch($message);

        return $message;
    }
}
