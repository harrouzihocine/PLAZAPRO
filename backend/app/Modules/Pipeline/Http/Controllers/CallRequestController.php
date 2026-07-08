<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Controllers;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Pipeline\Enums\CallRequestStatus;
use App\Modules\Pipeline\Events\CallRequestClosed;
use App\Modules\Pipeline\Models\CallRequest;
use App\Modules\Pipeline\Notifications\CallRequestNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Click-to-call: the web phone icon pushes the client's number to the agent's
 * own phone (store), the Android shell reports the dialer was opened (dialed —
 * which raises the "log this call?" prompt on every device), and answering the
 * prompt closes the request (close). All under calls.log: the whole point of
 * the handoff is the call log it ends in.
 */
class CallRequestController extends Controller
{
    public function store(Request $request, Client $client): JsonResponse
    {
        $user = $request->user();

        // A client outside the caller's scope reads as absent (mirrors the
        // calls / timeline endpoints).
        abort_unless(
            Client::query()->visibleTo($user)->whereKey($client->id)->exists(),
            404,
        );

        abort_if(blank($client->phone), 422, 'This client has no phone number.');

        abort_unless(
            config('services.fcm.credentials') && $user->deviceTokens()->exists(),
            422,
            'No phone connected — open the PLAZA PRO app on your phone once, then try again.',
        );

        // The reminder deep-links to the project timeline only when it is
        // unambiguous: exactly one active project the caller can see. Anything
        // else lands on the client file.
        $projectIds = ClientProject::query()
            ->where('client_id', $client->id)
            ->visibleTo($user)
            ->active()
            ->pluck('id');

        // One open request per user+client: a re-click re-pushes the same row
        // (the tray entry replaces itself by tag), never a second reminder.
        $callRequest = CallRequest::query()
            ->open()
            ->where('user_id', $user->id)
            ->where('client_id', $client->id)
            ->first();

        $attributes = [
            'client_project_id' => $projectIds->count() === 1 ? $projectIds->first() : null,
            'phone' => $client->phone,
        ];

        if ($callRequest === null) {
            $callRequest = CallRequest::create($attributes + [
                'user_id' => $user->id,
                'client_id' => $client->id,
                'status' => CallRequestStatus::Sent,
            ]);
        } else {
            // Refresh the target but never downgrade a DIALED request back to
            // sent: dialed_at (the audit trail) survives, and dialed()'s
            // once-only guard keeps a re-click from stacking a second prompt.
            $callRequest->update($attributes);
        }

        $user->notify(new CallRequestNotification($callRequest, $client->full_name));

        return response()->json(['data' => $this->payload($callRequest)], 201);
    }

    /**
     * The shell opened the dialer. Raises the cross-device "log this call?"
     * prompt: a DomainNotification (bell + live broadcast + a persistent tray
     * entry on the phone) the frontends turn into the actual dialog.
     */
    public function dialed(Request $request, CallRequest $callRequest): JsonResponse
    {
        abort_unless($callRequest->user_id === $request->user()->id, 403);

        // Only the first report prompts — a double tap on the tray entry (or a
        // replayed request) must not stack reminders.
        if ($callRequest->status === CallRequestStatus::Sent) {
            $callRequest->update([
                'status' => CallRequestStatus::Dialed,
                'dialed_at' => now(),
            ]);

            $request->user()->notify(new DomainNotification(
                kind: 'call_log_prompt',
                title: 'You called '.$callRequest->client->full_name,
                body: 'Need to log this phone call? Tap to open the call log.',
                link: $callRequest->link(),
                subjectType: 'call_request',
                subjectId: $callRequest->id,
            ));
        }

        return response()->json(['data' => $this->payload($callRequest)]);
    }

    /**
     * The prompt was answered: `logged` (a call log was created for it) or
     * `dismissed` ("not now"). Other open sessions are told to drop theirs.
     */
    public function close(Request $request, CallRequest $callRequest): JsonResponse
    {
        abort_unless($callRequest->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                CallRequestStatus::Logged->value, CallRequestStatus::Dismissed->value,
            ])],
        ]);

        if ($callRequest->status->isOpen()) {
            $callRequest->update(['status' => $validated['status']]);

            CallRequestClosed::dispatch(
                $callRequest->user_id, $callRequest->id, $validated['status'],
            );
        }

        return response()->json(['data' => $this->payload($callRequest)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CallRequest $callRequest): array
    {
        return [
            'id' => $callRequest->id,
            'status' => $callRequest->status->value,
            'link' => $callRequest->link(),
        ];
    }
}
