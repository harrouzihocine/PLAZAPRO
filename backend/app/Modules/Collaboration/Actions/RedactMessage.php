<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Actions;

use App\Modules\Collaboration\Models\Message;

/**
 * "Delete" a message = redact it: cancel the row (no-delete) so the thread keeps
 * the position and audit trail. Attachments are hidden by the resource once the
 * message is cancelled; the stored files are intentionally kept.
 */
class RedactMessage
{
    public function handle(Message $message, string $reason = 'Message deleted'): Message
    {
        return $message->cancel($reason);
    }
}
