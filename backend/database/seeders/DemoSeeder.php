<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Clients\Actions\CreateClient;
use App\Modules\Clients\Actions\CreateClientProject;
use App\Modules\Clients\Actions\UpsertDesire;
use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Models\Client;
use App\Modules\Collaboration\Actions\CreateConversation;
use App\Modules\Collaboration\Actions\SendMessage;
use App\Modules\Collaboration\Enums\ConversationType;
use App\Modules\Inventory\Actions\ConvertReservation;
use App\Modules\Inventory\Actions\CreateLocation;
use App\Modules\Inventory\Actions\CreateUnit;
use App\Modules\Inventory\Actions\ReserveUnit;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Payments\Actions\RecordVersement;
use App\Modules\Payments\Actions\SaveSchedule;
use App\Modules\Pipeline\Actions\CreateTask;
use App\Modules\Pipeline\Actions\LogCall;
use App\Modules\Pipeline\Actions\ScheduleVisit;
use App\Modules\Pipeline\Enums\CallDirection;
use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Pipeline\Enums\TaskPriority;
use App\Modules\Pipeline\Enums\VisitType;
use App\Modules\Settings\Actions\CreateUser;
use App\Modules\Settings\Models\Department;
use App\Modules\Settings\Models\DynamicList;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use App\Modules\Settings\Models\Wilaya;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Realistic sample data so the app "looks alive" for a walkthrough: agents,
 * inventory (with a live 48h interest hold and a sold unit), clients, deals across every
 * pipeline stage, calls/visits/tasks (some overdue), a won deal with a part-paid
 * payment plan, and two chat threads.
 *
 * Everything goes through the real domain Actions, so derived state (sale_status,
 * schedule state, deal stage, activity log) is exactly what the app would produce.
 * NOT wired into DatabaseSeeder — run explicitly: `php artisan db:seed --class=DemoSeeder`.
 * Idempotent by a skip-guard on the first demo agent.
 */
class DemoSeeder extends Seeder
{
    /** @var array<string, array<string, int>> memoised dynamic-list value => id */
    private array $listCache = [];

    public function run(): void
    {
        $admin = User::where('email', 'admin@plaza.local')->first();
        if ($admin === null) {
            $this->command->warn('Base seeders must run first (admin user missing). Aborting demo seed.');

            return;
        }

        if (User::where('email', 'sarah@plaza.local')->exists()) {
            $this->command->info('Demo data already present — nothing to do.');

            return;
        }

        // Give the audit trail (LogsActivity) a real actor for every write below.
        Auth::login($admin);

        $this->command->info('Seeding demo data…');

        // Sales agents: they log calls and follow clients up (calls.log), so they
        // are valid client "assigned agents". A site-agent (visits only, no
        // calls.log) would violate the CanFollowUpClient invariant on every client.
        $agentRole = Role::where('slug', 'sales-agent')->firstOrFail();
        $sales = Department::firstOrCreate(['slug' => 'ventes'], ['name' => 'Ventes']);

        // --- Agents (also the chat participants) ------------------------------
        $sarah = app(CreateUser::class)->handle([
            'name' => 'Sarah Bensalem', 'email' => 'sarah@plaza.local', 'password' => 'password',
            'role_id' => $agentRole->id, 'department_id' => $sales->id, 'phone' => '0550 11 22 33',
        ]);
        $karim = app(CreateUser::class)->handle([
            'name' => 'Karim Haddad', 'email' => 'karim@plaza.local', 'password' => 'password',
            'role_id' => $agentRole->id, 'department_id' => $sales->id, 'phone' => '0551 44 55 66',
        ]);
        $amina = app(CreateUser::class)->handle([
            'name' => 'Amina Toumi', 'email' => 'amina@plaza.local', 'password' => 'password',
            'role_id' => $agentRole->id, 'department_id' => $sales->id, 'phone' => '0552 77 88 99',
        ]);

        // --- Inventory --------------------------------------------------------
        $createLocation = app(CreateLocation::class);
        $elFeth = $createLocation->handle([
            'name' => 'Résidence El Feth', 'code' => 'REF', 'wilaya_id' => $this->wilaya('Alger'),
            'type_id' => $this->item('project_types', 'akam_mftoh'),
            // Financing options this project offers buyers (project_payment_methods).
            'payment_method_ids' => [
                $this->item('project_payment_methods', 'alkrd_albnky_mtofr'),
                $this->item('project_payment_methods', 'amkany_altksyt'),
                $this->item('project_payment_methods', 'aldfaa_kash'),
            ],
            'address' => '12 Rue des Frères Boughedou, Alger', 'description' => 'Standing residence, 5 floors.',
        ]);
        $oran = $createLocation->handle([
            'name' => "Les Jardins d'Oran", 'code' => 'LJO', 'wilaya_id' => $this->wilaya('Oran'),
            'type_id' => $this->item('project_types', 'akam_mghlk'),
            'payment_method_ids' => [
                $this->item('project_payment_methods', 'alkrd_albnky_ghyr_mtofr'),
                $this->item('project_payment_methods', 'aldfaa_kash'),
            ],
            'address' => 'Route de Sénia, Oran', 'description' => 'Family project near the coast.',
        ]);

        // $type here is the room layout (F2 / F3 / …), stored on the unit; project
        // type lives on the location above.
        $u = fn (Location $loc, string $ref, string $type, string $floor, float $sqm, string $price, string $block, int $sf, int $pos): Unit => app(CreateUnit::class)->handle($loc, [
            'reference' => $ref, 'room_number_id' => $this->item('room_numbers', $type === 'duplex' ? 'dublex' : $type),
            'floor_id' => $this->item('floors', $floor),
            'area_sqm' => $sqm, 'price' => $price, 'block' => $block, 'stack_floor' => $sf, 'position' => $pos,
        ]);

        // Block A @ El Feth
        $u($elFeth, 'A-01', 'studio', 'ground', 38.5, '3200000.00', 'A', 0, 1);
        $u($elFeth, 'A-11', 'f2', 'floor_1', 55.0, '4600000.00', 'A', 1, 1);
        $unitA21 = $u($elFeth, 'A-21', 'f3', 'floor_2', 78.0, '6800000.00', 'A', 2, 1);
        $unitA31 = $u($elFeth, 'A-31', 'f4', 'floor_3', 96.0, '8400000.00', 'A', 3, 1); // -> sold
        $unitA41 = $u($elFeth, 'A-41', 'f3', 'floor_4', 80.0, '7000000.00', 'A', 4, 1); // -> interested
        // Block B @ Oran
        $u($oran, 'B-01', 'f2', 'ground', 52.0, '4200000.00', 'B', 0, 2);
        $u($oran, 'B-12', 'f3', 'floor_1', 75.0, '6300000.00', 'B', 1, 2);
        $u($oran, 'B-22', 'duplex', 'floor_2', 120.0, '11500000.00', 'B', 2, 2);
        $u($oran, 'B-32', 'f4', 'floor_3', 98.0, '8900000.00', 'B', 3, 2);

        // --- Clients ----------------------------------------------------------
        $client = fn (string $first, string $last, string $phone, string $email, string $source, string $rating, User $agent, string $notes): Client => app(CreateClient::class)->handle([
            'first_name' => $first, 'last_name' => $last, 'phone' => $phone, 'email' => $email,
            'source_id' => $this->item('sources', $source), 'rating_id' => $this->item('client_ratings', $rating),
            'assigned_agent_id' => $agent->id, 'notes' => $notes,
        ]);

        $c1 = $client('Yacine', 'Meziane', '0550 12 34 56', 'yacine.meziane@example.dz', 'referral', 'hot', $sarah, 'Cherche un F4 à El Feth, budget ~8.5M.');
        $c2 = $client('Nadia', 'Cherif', '0551 23 45 67', 'nadia.cherif@example.dz', 'facebook', 'warm', $karim, 'Intéressée par le F3 A-41.');
        $c3 = $client('Sofiane', 'Belkacem', '0552 34 56 78', 'sofiane.b@example.dz', 'walk_in', 'hot', $sarah, 'Veut visiter rapidement.');
        $c4 = $client('Lamia', 'Bouzid', '0553 45 67 89', 'lamia.bouzid@example.dz', 'instagram', 'cold', $amina, 'Premier contact, à recontacter.');
        $c5 = $client('Rachid', 'Oualid', '0554 56 78 90', 'rachid.oualid@example.dz', 'portal', 'warm', $karim, 'Budget serré, zone Oran.');
        $c6 = $client('Farida', 'Slimani', '0555 67 89 01', 'farida.slimani@example.dz', 'referral', 'warm', $amina, 'A finalement choisi un autre promoteur.');

        // --- Desires (what a few clients are looking for) ---------------------
        $desire = app(UpsertDesire::class);
        $desire->handle($c1, ['wilaya_id' => $this->wilaya('Alger'), 'type_id' => $this->item('project_types', 'akam_mftoh'), 'room_number_id' => $this->item('room_numbers', 'f4'), 'floor_pref' => 'floor_3', 'budget_min' => '7000000.00', 'budget_max' => '9000000.00', 'notes' => 'Étage élevé de préférence.']);
        $desire->handle($c2, ['wilaya_id' => $this->wilaya('Alger'), 'type_id' => $this->item('project_types', 'akam_mftoh'), 'room_number_id' => $this->item('room_numbers', 'f3'), 'budget_min' => '6000000.00', 'budget_max' => '7500000.00']);
        $desire->handle($c4, ['wilaya_id' => $this->wilaya('Oran'), 'type_id' => $this->item('project_types', 'akam_mghlk'), 'room_number_id' => $this->item('room_numbers', 'f2'), 'budget_min' => '3800000.00', 'budget_max' => '5000000.00']);

        // --- Deals (one per stage) -------------------------------------------
        $mkDeal = app(CreateClientProject::class);
        // Won: negotiate -> hold -> convert (unit sold, deal won, price stamped).
        $d1 = $mkDeal->handle($c1, ['location_id' => $elFeth->id, 'unit_id' => $unitA31->id, 'stage' => ClientProjectStage::Negotiating->value]);
        $resA31 = app(ReserveUnit::class)->handle($unitA31, ['client_project_id' => $d1->id], $sarah);
        app(ConvertReservation::class)->handle($resA31);
        $d1->refresh();

        // Interested: live 48h hold on A-41 (countdown in the UI).
        $d2 = $mkDeal->handle($c2, ['location_id' => $elFeth->id, 'unit_id' => $unitA41->id, 'stage' => ClientProjectStage::Deal->value]);
        app(ReserveUnit::class)->handle($unitA41, ['client_project_id' => $d2->id], $karim);

        // Negotiating, lead x2, lost.
        $d3 = $mkDeal->handle($c3, ['location_id' => $elFeth->id, 'unit_id' => $unitA21->id, 'stage' => ClientProjectStage::Negotiating->value, 'total_price' => '6800000.00']);
        $d4 = $mkDeal->handle($c5, ['location_id' => $oran->id, 'stage' => ClientProjectStage::Lead->value]);
        $mkDeal->handle($c4, ['location_id' => $oran->id, 'stage' => ClientProjectStage::Lead->value]);
        $mkDeal->handle($c6, ['location_id' => $elFeth->id, 'stage' => ClientProjectStage::Lost->value]);

        // --- Calls (each leaves the enforced next action; one overdue) --------
        $logCall = app(LogCall::class);
        $logCall->handle($c1, ['client_project_id' => $d1->id, 'agent_id' => $sarah->id, 'direction' => CallDirection::Outbound->value, 'outcome_id' => $this->item('call_outcomes', 'interested'), 'notes' => 'Prêt à signer, mais trouve le prix un peu élevé.', 'objections' => [$this->item('objection_reasons', 'price_too_high')], 'called_at' => now()->subDays(3), 'next_action' => ['type' => NextActionType::Call->value, 'due_at' => now()->addDay(), 'assigned_to' => $sarah->id]], $sarah);
        $logCall->handle($c2, ['client_project_id' => $d2->id, 'agent_id' => $karim->id, 'direction' => CallDirection::Outbound->value, 'outcome_id' => $this->item('call_outcomes', 'callback_requested'), 'notes' => 'Rappeler demain matin. Voudrait un échéancier plus long.', 'objections' => [$this->item('objection_reasons', 'payment_plan_too_short'), $this->item('objection_reasons', 'price_too_high')], 'called_at' => now()->subDay(), 'next_action' => ['type' => NextActionType::Call->value, 'due_at' => now()->addDay(), 'assigned_to' => $karim->id]], $karim);
        $logCall->handle($c3, ['client_project_id' => $d3->id, 'agent_id' => $sarah->id, 'direction' => CallDirection::Inbound->value, 'outcome_id' => $this->item('call_outcomes', 'interested'), 'notes' => 'Souhaite visiter le A-21.', 'called_at' => now()->subDays(2), 'properties' => [['shortlistable_type' => 'unit', 'shortlistable_id' => $unitA21->id]], 'next_action' => ['type' => NextActionType::InSiteVisit->value, 'due_at' => now()->addDays(2), 'assigned_to' => $sarah->id]], $sarah);
        $logCall->handle($c5, ['client_project_id' => $d4->id, 'agent_id' => $karim->id, 'direction' => CallDirection::Outbound->value, 'outcome_id' => $this->item('call_outcomes', 'no_answer'), 'notes' => 'Pas de réponse.', 'called_at' => now()->subDays(4), 'next_action' => ['type' => NextActionType::Call->value, 'due_at' => now()->subDay(), 'assigned_to' => $karim->id]], $karim); // overdue

        // --- Visits -----------------------------------------------------------
        // Sofiane's in-site visit of A-21 is auto-generated by his call above (the
        // in-site next action materializes one pending field visit per shortlisted
        // unit). Only the ad-hoc office visit is scheduled manually here.
        $visit = app(ScheduleVisit::class);
        $visit->handle(['client_id' => $c1->id, 'client_project_id' => $d1->id, 'type' => VisitType::Office->value, 'agent_id' => $sarah->id, 'scheduled_at' => now()->subDays(5), 'notes' => 'Rendez-vous bureau — signature à venir.']);

        // --- Tasks (some overdue / high priority) -----------------------------
        $task = app(CreateTask::class);
        $task->handle(['title' => 'Préparer le contrat de vente — Yacine Meziane', 'assigned_to' => $sarah->id, 'due_at' => now()->addDay(), 'priority' => TaskPriority::High->value, 'subject_type' => $d1->getMorphClass(), 'subject_id' => $d1->id], $admin);
        $task->handle(['title' => 'Rappeler Rachid Oualid', 'assigned_to' => $karim->id, 'due_at' => now()->subDay(), 'priority' => TaskPriority::High->value], $admin); // overdue
        $task->handle(['title' => 'Envoyer la brochure à Lamia Bouzid', 'assigned_to' => $amina->id, 'due_at' => now()->addDays(3), 'priority' => TaskPriority::Normal->value], $admin);
        $task->handle(['title' => 'Mettre à jour la grille tarifaire El Feth', 'assigned_to' => $sarah->id, 'due_at' => now()->addDays(5), 'priority' => TaskPriority::Low->value], $admin);
        $task->handle(['title' => 'Préparer le rapport hebdomadaire', 'assigned_to' => $karim->id, 'due_at' => now()->addDays(2), 'priority' => TaskPriority::Normal->value], $admin);

        // --- Payments on the won deal (plan reconciles to 8.4M; part-paid) ----
        // Per-apartment tracking: the plan and every payment carry the sold unit.
        $schedule = app(SaveSchedule::class)->handle($d1, ['unit_id' => $unitA31->id, 'installments' => [
            ['due_date' => now()->subDays(30)->toDateString(), 'amount' => '2800000.00'],
            ['due_date' => now()->subDays(5)->toDateString(), 'amount' => '2800000.00'],
            ['due_date' => now()->addDays(60)->toDateString(), 'amount' => '2800000.00'],
        ]])->values();

        $recordVersement = app(RecordVersement::class);
        $recordVersement->handle($d1, ['unit_id' => $unitA31->id, 'amount' => '2800000.00', 'paid_on' => now()->subDays(28)->toDateString(), 'method_id' => $this->item('payment_methods', 'bank_transfer'), 'reference' => 'VIR-2026-0012', 'schedule_item_id' => $schedule[0]->id], $admin); // -> paid
        $recordVersement->handle($d1, ['unit_id' => $unitA31->id, 'amount' => '1000000.00', 'paid_on' => now()->subDays(2)->toDateString(), 'method_id' => $this->item('payment_methods', 'cheque'), 'reference' => 'CHQ-0098', 'schedule_item_id' => $schedule[1]->id], $admin); // -> overdue (partial, past due)

        // --- Chat -------------------------------------------------------------
        $newConversation = app(CreateConversation::class);
        $send = app(SendMessage::class);

        $direct = $newConversation->handle($admin, ['type' => ConversationType::Direct->value, 'participant_ids' => [$sarah->id]]);
        $send->handle($direct, $sarah, 'Bonjour ! Yacine Meziane est prêt à signer pour le A-31. 🎉');
        $send->handle($direct, $admin, 'Excellent travail. On prépare le contrat aujourd’hui ?');
        $send->handle($direct, $sarah, 'Oui, je m’en occupe cet après-midi.');

        $group = $newConversation->handle($admin, ['type' => ConversationType::Group->value, 'title' => 'Équipe Ventes', 'participant_ids' => [$sarah->id, $karim->id, $amina->id]]);
        $send->handle($group, $admin, 'Bienvenue dans le canal de l’équipe ventes 👋');
        $send->handle($group, $karim, 'Merci ! 2 nouveaux leads cette semaine.');
        $send->handle($group, $amina, 'Super. Réunion vendredi 10h, n’oubliez pas.');
        $send->handle($group, $sarah, 'Noté 📅');

        Auth::logout();

        $this->command->info('Demo data seeded: 3 agents, 2 locations, 9 units, 6 clients, 6 deals, calls/visits/tasks, a part-paid won deal and 2 chat threads.');
    }

    /** Resolve a dynamic-list item id by its list key + value (memoised). */
    private function item(string $listKey, string $value): int
    {
        if (! isset($this->listCache[$listKey])) {
            $listId = DynamicList::where('key', $listKey)->value('id');
            $this->listCache[$listKey] = DynamicListItem::where('dynamic_list_id', $listId)
                ->pluck('id', 'value')->all();
        }

        $id = $this->listCache[$listKey][$value] ?? null;
        abort_if($id === null, 500, "Missing dynamic-list item {$listKey}:{$value} — run DynamicListSeeder first.");

        return (int) $id;
    }

    /** Resolve a wilaya id by its name. */
    private function wilaya(string $name): int
    {
        $id = Wilaya::where('name', $name)->value('id');
        abort_if($id === null, 500, "Missing wilaya {$name} — run WilayaCommuneSeeder first.");

        return (int) $id;
    }
}
