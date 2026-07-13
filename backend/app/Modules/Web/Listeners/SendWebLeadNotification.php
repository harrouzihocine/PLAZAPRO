<?php

declare(strict_types=1);

namespace App\Modules\Web\Listeners;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Settings\Models\User;
use App\Modules\Web\Enums\WebLeadType;
use App\Modules\Web\Events\WebLeadCreated;
use App\Modules\Web\Models\WebLead;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A new website lead landed: ring every web.leads holder so somebody follows
 * up while the visitor is still warm. Mirrors
 * SendOfficeVisitApprovalRequestNotification — same fan-out idiom, different desk.
 */
class SendWebLeadNotification implements ShouldQueue
{
    // The lead row must be committed before a fast worker restores the model.
    public bool $afterCommit = true;

    public function handle(WebLeadCreated $event): void
    {
        $lead = $event->webLead->load(['location:id,name', 'unit:id,location_id,reference']);

        // role.permissions eager-loaded: can() → hasPermission() then reads the
        // loaded collection instead of one query per user (N+1).
        $recipients = User::query()
            ->active()
            ->where('is_active', true)
            ->with('role.permissions')
            ->get()
            ->filter(fn (User $u) => $u->can('web.leads'));

        foreach ($recipients as $recipient) {
            $recipient->notify(new DomainNotification(
                kind: 'web_lead',
                key: 'web_lead',
                params: [
                    'name' => $lead->name,
                    'about' => $lead->type === WebLeadType::Desire
                        ? '@notifications.web_lead_about_desire'
                        : ($lead->unit?->reference
                            ?? $lead->location?->name
                            ?? '@notifications.web_lead_about_general'),
                ],
                link: '/web-leads',
                subjectType: WebLead::class,
                subjectId: $lead->id,
            ));
        }
    }
}
