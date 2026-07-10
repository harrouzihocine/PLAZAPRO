<?php

declare(strict_types=1);

// Notification titles/bodies, resolved per RECIPIENT (DomainNotification::key).
// fr/ar mirror this file key for key. Params prefixed with '@' at the call
// site resolve against the `type`/`group` sub-arrays below.
return [
    'type' => [
        'office' => 'office',
        'in_site' => 'in-site',
    ],
    'group' => [
        'calls' => 'call(s)',
        'office_visits' => 'office visit(s)',
        'in_site_visits' => 'in-site visit(s)',
        'tasks' => 'task(s)',
    ],

    'empty_client' => [
        'title' => 'Empty client — follow up',
        'body' => 'You captured :name but nothing has been logged yet.',
    ],
    'client_assigned' => [
        'title' => 'A waiting client was assigned to you',
        'body' => 'Reconnect with :name — their wishlist now fits available inventory.',
    ],
    'project_set_up' => [
        'title' => 'A client was set up for you',
        'body' => 'A supervisor set this client up as your own project — open it to start.',
    ],
    'project_shared' => [
        'title' => 'A project was shared with you',
        'body' => 'A supervisor shared an existing client’s project with you.',
    ],
    'project_handed' => [
        'title' => 'A project was handed to you',
        'body' => 'A supervisor reactivated a client project and put you on it — open it to continue.',
    ],
    'duplicate_denied' => [
        'title' => 'Duplicate request declined',
        'body' => 'Your request to add an existing client was declined.',
    ],
    'duplicate_attempt' => [
        'title' => 'Duplicate client attempt',
        'body' => ':name tried to add a client that already exists.',
    ],
    'account_locked' => [
        'title' => 'Account locked: :name',
        'body' => ':attempts failed sign-in attempts. Unlock it from the Users page.',
    ],
    'dispatch_request' => [
        'title' => 'An in-site visit needs an agent',
        'body' => 'Visit for :client due :date — assign a field agent on the board.',
    ],
    'work_transferred' => [
        'title' => ":from's open work was handed to you",
        'body' => "You received :count open item(s). Your dashboard's upcoming list has the details.",
    ],
    'plans_pooled' => [
        'title' => 'Field plans returned to the pool',
        'body' => ':count in-site plan(s) from :from went back to the pool — assign new agents on the board.',
    ],
    'queue_first' => [
        'title' => 'Your client is now first in line',
        'body' => 'The reservation on :unit was released — your client is next. Call them before the unit moves.',
    ],
    'queue_cancelled' => [
        'title' => 'Reservation cancelled — unit sold',
        'body' => ':unit was sold to another client. Your client’s reservation (was #:position in line) is cancelled.',
    ],
    'upcoming_digest' => [
        'title' => 'You have :count :group coming up',
        'body' => ':lines',
    ],
    'upcoming_digest_overdue' => [
        'title' => 'You have :count :group coming up (:overdue overdue)',
        'body' => ':lines',
    ],
    'digest_more' => '… and :count more',
    'visit_assigned' => [
        'title' => 'A visit was assigned to you',
        'body' => ':type visit with :client:extra.',
    ],
    'visit_agent_assigned' => [
        'title' => 'In-site agent assigned',
        'body' => ':agent will handle the :type visit with :client:extra.',
    ],
    'office_visit_scheduled' => [
        'title' => 'Upcoming office visit',
        'body' => ':type visit with :client:extra — with :agent.',
    ],
    'office_visit_approval' => [
        'title' => 'An office visit needs your approval',
        'body' => ':user wants an office visit with :client on :date — beyond the :days-day window. Approve, deny or reschedule it on the program page.',
    ],
    'office_visit_approved' => [
        'title' => 'Office visit approved',
        'body' => ':manager approved the office visit with :client on :date.',
    ],
    'office_visit_denied' => [
        'title' => 'Office visit denied — plan a new one',
        'body' => ':manager denied the office visit with :client on :date (:reason). Plan a closer date with the client.',
    ],
    'office_visit_rescheduled' => [
        'title' => 'Office visit rescheduled',
        'body' => ':manager moved the office visit with :client to :date.',
    ],
    'unit_match_new' => [
        'title' => 'New unit matches a client',
        'body' => 'Unit :unit:details fits: :clients.',
    ],
    'unit_match_repriced' => [
        'title' => 'A matching unit was repriced',
        'body' => 'Unit :unit:details fits: :clients.',
    ],
    'call_prompt' => [
        'title' => 'You called :name',
        'body' => 'Need to log this phone call? Tap to open the call log.',
    ],
    'reminder' => [
        'title' => 'A follow-up is due',
        'body' => 'Your next action (:type) is due now.',
    ],
    'reminder_type' => [
        'call' => 'call',
        'office_visit' => 'office visit',
        'in_site_visit' => 'in-site visit',
    ],
    'task_reminder' => [
        'title' => 'A task is due',
        'body' => 'Your task ":title" is due now.',
    ],
    'task_assigned' => [
        'title' => 'New task for you',
        'body' => ':name gave you a task: ":title".',
    ],
    'task_completed' => [
        'title' => 'Task done — review it',
        'body' => ':name finished ":title" (:outcome). Open the board to read the report.',
    ],
    'task_outcome' => [
        'full' => 'fully done',
        'partial' => 'partially done',
        'issues' => 'done with difficulties',
    ],
    'payment' => [
        'title' => 'Payment recorded',
        'body' => "A payment of :amount was recorded on :client's deal.",
    ],
    'reserved_lapsed' => [
        'title' => 'Reservation expired',
        'body' => 'The reservation on :unit lapsed — it is back on the market.',
    ],
    'unit_published' => [
        'title' => 'New unit added',
        'body' => ':details',
    ],
    'box_published' => [
        'title' => 'New box added',
        'body' => ':details',
    ],
    'unit_updated' => [
        'title' => 'Unit :unit updated',
        'body' => ':details',
    ],
    'units_imported' => [
        'title' => 'Inventory imported',
        'body' => ':user imported units from a file — :created added, :updated updated.',
    ],
    'box_updated' => [
        'title' => 'Box :box updated',
        'body' => ':details',
    ],
    'unit_sold' => [
        'title' => 'Unit sold 🎉',
        'body' => ':details',
    ],
    'unit_status' => [
        'title' => 'Unit :unit — :status',
        'body' => ':status:details',
    ],
    'unit_status_label' => [
        'interested' => 'interested',
        'reserved' => 'reserved',
        'available' => 'back on the market',
    ],
    'chat_message' => [
        'title' => ':title',
        'body' => ':preview',
    ],
    // First message a sender has ever written to this recipient — the only
    // chat traffic that lands in the bell feed.
    'chat_first_message' => [
        'title' => 'New chat from :name',
        'body' => ':preview',
    ],
    'chat_group' => 'Group chat',
    'chat_new_message' => 'New message',
    'chat_attachment' => 'Sent an attachment',
    'message_deleted' => 'Message deleted',
    'message_photo' => '📷 Photo',
    'message_voice' => '🎤 Voice note',
    'message_file' => '📎 File',

    // The dispatch GPS layer: an agent bounced an assignment back to the pool,
    // an assignment sat unaccepted past the SLA, an arrival is running late.
    'visit_declined' => [
        'title' => 'Visit declined',
        'body' => ':agent declined the visit with :client:extra — ":reason".',
    ],
    'visit_unaccepted' => [
        'title' => 'Assignment not accepted yet',
        'body' => ':agent has not accepted the visit with :client (:when).',
    ],
    'visit_late' => [
        'title' => 'Agent running late',
        'body' => ':agent has not arrived for the visit with :client (:when).',
    ],

    // Dispatcher pulled a fresh fix (silent on new shells; older shells show
    // this quiet line — transparency, not spam).
    'locate_request' => [
        'title' => 'Position check',
        'body' => 'Dispatch refreshed your live position.',
    ],
];
