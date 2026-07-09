<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Console;

use App\Modules\Collaboration\Enums\AttachmentKind;
use App\Modules\Collaboration\Jobs\OptimizeAttachment;
use App\Modules\Collaboration\Models\MessageAttachment;
use App\Modules\Inventory\Jobs\OptimizeMedia;
use App\Modules\Inventory\Models\Media;
use Illuminate\Console\Command;

/**
 * Backfill the optimization pipeline over media uploaded before it existed
 * (or retry failures):
 *
 *   php artisan media:optimize            # queue everything untouched
 *   php artisan media:optimize --retry-failed
 *   php artisan media:optimize --limit=50 # batch gently
 *
 * Only ACTIVE gallery rows are queued — cancelled/replaced versions keep their
 * original bytes as history. Jobs land on the dedicated `media` queue, so a big
 * backlog never starves chat/notification jobs.
 */
class OptimizeExistingMedia extends Command
{
    protected $signature = 'media:optimize
        {--retry-failed : Re-queue rows whose last optimization failed}
        {--limit=0 : Queue at most N of each kind (0 = all)}';

    protected $description = 'Queue optimization (WebP / 1080p H.264 + thumbnails) for existing photos, videos and chat images';

    public function handle(): int
    {
        $retryFailed = (bool) $this->option('retry-failed');
        $limit = max(0, (int) $this->option('limit'));

        // NULL status = never touched by the pipeline (IN (NULL) matches nothing,
        // hence the explicit whereNull branch).
        $eligible = fn ($q) => $retryFailed
            ? $q->where('optimize_status', 'failed')
            : $q->whereNull('optimize_status');

        $media = Media::query()
            ->active()
            ->whereIn('type', ['photo', 'video'])
            ->tap($eligible)
            ->when($limit > 0, fn ($q) => $q->limit($limit))
            ->pluck('id');

        Media::whereIn('id', $media)->update(['optimize_status' => 'pending']);
        foreach ($media as $id) {
            OptimizeMedia::dispatch($id);
        }

        $attachments = MessageAttachment::query()
            ->where('kind', AttachmentKind::Image->value)
            ->where('mime_type', '!=', 'image/gif')
            ->tap($eligible)
            ->when($limit > 0, fn ($q) => $q->limit($limit))
            ->pluck('id');

        MessageAttachment::whereIn('id', $attachments)->update(['optimize_status' => 'pending']);
        foreach ($attachments as $id) {
            OptimizeAttachment::dispatch($id);
        }

        $this->info(sprintf(
            'Queued %d gallery file(s) on the media queue and %d chat image(s) on the default queue.',
            $media->count(),
            $attachments->count(),
        ));

        return self::SUCCESS;
    }
}
