<?php

declare(strict_types=1);

// Titres/corps des notifications, résolus par DESTINATAIRE — miroir de en/notifications.php.
return [
    'type' => [
        'office' => 'bureau',
        'in_site' => 'sur site',
    ],
    'group' => [
        'calls' => 'appel(s)',
        'office_visits' => 'visite(s) bureau',
        'in_site_visits' => 'visite(s) sur site',
        'tasks' => 'tâche(s)',
    ],

    'empty_client' => [
        'title' => 'Client vide — à relancer',
        'body' => 'Vous avez saisi :name mais rien n’a encore été enregistré.',
    ],
    'client_assigned' => [
        'title' => 'Un client en attente vous a été assigné',
        'body' => 'Recontactez :name — sa liste de souhaits correspond maintenant à l’inventaire disponible.',
    ],
    'project_set_up' => [
        'title' => 'Un client a été mis en place pour vous',
        'body' => 'Un superviseur a configuré ce client comme votre propre projet — ouvrez-le pour commencer.',
    ],
    'project_shared' => [
        'title' => 'Un projet a été partagé avec vous',
        'body' => 'Un superviseur a partagé avec vous le projet d’un client existant.',
    ],
    'project_handed' => [
        'title' => 'Un projet vous a été confié',
        'body' => 'Un superviseur a réactivé un projet client et vous y a placé — ouvrez-le pour continuer.',
    ],
    'duplicate_denied' => [
        'title' => 'Demande de doublon refusée',
        'body' => 'Votre demande d’ajout d’un client existant a été refusée.',
    ],
    'duplicate_attempt' => [
        'title' => 'Tentative de client en doublon',
        'body' => ':name a tenté d’ajouter un client qui existe déjà.',
    ],
    'account_locked' => [
        'title' => 'Compte verrouillé : :name',
        'body' => ':attempts tentatives de connexion échouées. Déverrouillez-le depuis la page Utilisateurs.',
    ],
    'dispatch_request' => [
        'title' => 'Une visite sur site attend un agent',
        'body' => 'Visite pour :client prévue :date — assignez un agent de terrain sur le tableau.',
    ],
    'work_transferred' => [
        'title' => 'Le travail ouvert de :from vous a été confié',
        'body' => 'Vous avez reçu :count élément(s) ouvert(s). La liste « à venir » de votre tableau de bord a les détails.',
    ],
    'plans_pooled' => [
        'title' => 'Des plans de terrain sont revenus dans la file',
        'body' => ':count plan(s) sur site de :from sont revenus dans la file — assignez de nouveaux agents sur le tableau.',
    ],
    'queue_first' => [
        'title' => 'Votre client est maintenant premier en liste',
        'body' => 'La réservation sur :unit a été libérée — votre client est le prochain. Appelez-le avant que l’unité ne parte.',
    ],
    'queue_cancelled' => [
        'title' => 'Réservation annulée — unité vendue',
        'body' => ':unit a été vendue à un autre client. La réservation de votre client (n° :position en liste) est annulée.',
    ],
    'upcoming_digest' => [
        'title' => 'Vous avez :count :group à venir',
        'body' => ':lines',
    ],
    'upcoming_digest_overdue' => [
        'title' => 'Vous avez :count :group à venir (:overdue en retard)',
        'body' => ':lines',
    ],
    'digest_more' => '… et :count de plus',
    'visit_assigned' => [
        'title' => 'Une visite vous a été assignée',
        'body' => 'Visite :type avec :client:extra.',
    ],
    'visit_agent_assigned' => [
        'title' => 'Agent sur site assigné',
        'body' => ':agent s’occupera de la visite :type avec :client:extra.',
    ],
    'office_visit_scheduled' => [
        'title' => 'Visite bureau à venir',
        'body' => 'Visite :type avec :client:extra — avec :agent.',
    ],
    'office_visit_approval' => [
        'title' => 'Une visite bureau attend votre accord',
        'body' => ':user souhaite une visite bureau avec :client le :date — au-delà de la fenêtre de :days jour(s). Approuvez, refusez ou replanifiez-la depuis la page programme.',
    ],
    'office_visit_approved' => [
        'title' => 'Visite bureau approuvée',
        'body' => ':manager a approuvé la visite bureau avec :client le :date.',
    ],
    'office_visit_denied' => [
        'title' => 'Visite bureau refusée — à replanifier',
        'body' => ':manager a refusé la visite bureau avec :client du :date (:reason). Planifiez une date plus proche avec le client.',
    ],
    'office_visit_rescheduled' => [
        'title' => 'Visite bureau replanifiée',
        'body' => ':manager a déplacé la visite bureau avec :client au :date.',
    ],
    'unit_match_new' => [
        'title' => 'Une nouvelle unité correspond à un client',
        'body' => 'L’unité :unit:details correspond à : :clients.',
    ],
    'unit_match_repriced' => [
        'title' => 'Une unité correspondante a changé de prix',
        'body' => 'L’unité :unit:details correspond à : :clients.',
    ],
    'call_prompt' => [
        'title' => 'Vous avez appelé :name',
        'body' => 'Enregistrer cet appel ? Touchez pour ouvrir le journal d’appel.',
    ],
    'reminder' => [
        'title' => 'Un suivi est à échéance',
        'body' => 'Votre prochaine action (:type) est due maintenant.',
    ],
    'reminder_type' => [
        'call' => 'appel',
        'office_visit' => 'visite bureau',
        'in_site_visit' => 'visite sur site',
    ],
    'task_reminder' => [
        'title' => 'Une tâche est à échéance',
        'body' => 'Votre tâche « :title » est due maintenant.',
    ],
    'task_assigned' => [
        'title' => 'Nouvelle tâche pour vous',
        'body' => ':name vous a confié une tâche : « :title ».',
    ],
    'task_completed' => [
        'title' => 'Tâche terminée — à relire',
        'body' => ':name a terminé « :title » (:outcome). Ouvrez le tableau pour lire le rapport.',
    ],
    'task_outcome' => [
        'full' => 'entièrement faite',
        'partial' => 'partiellement faite',
        'issues' => 'faite avec difficultés',
    ],
    'payment' => [
        'title' => 'Paiement enregistré',
        'body' => 'Un paiement de :amount a été enregistré sur le deal de :client.',
    ],
    'reserved_lapsed' => [
        'title' => 'Réservation expirée',
        'body' => 'La réservation sur :unit a expiré — elle est de retour sur le marché.',
    ],
    'unit_published' => [
        'title' => 'Nouvelle unité ajoutée',
        'body' => ':details',
    ],
    'box_published' => [
        'title' => 'Nouveau box ajouté',
        'body' => ':details',
    ],
    'unit_updated' => [
        'title' => 'Unité :unit mise à jour',
        'body' => ':details',
    ],
    'units_imported' => [
        'title' => 'Inventaire importé',
        'body' => ':user a importé des unités depuis un fichier — :created ajoutées, :updated mises à jour.',
    ],
    'box_updated' => [
        'title' => 'Box :box mis à jour',
        'body' => ':details',
    ],
    'unit_sold' => [
        'title' => 'Unité vendue 🎉',
        'body' => ':details',
    ],
    'unit_status' => [
        'title' => 'Unité :unit — :status',
        'body' => ':status:details',
    ],
    'unit_status_label' => [
        'interested' => 'intéressé',
        'reserved' => 'réservée',
        'available' => 'de retour sur le marché',
    ],
    'chat_message' => [
        'title' => ':title',
        'body' => ':preview',
    ],
    // First message a sender has ever written to this recipient — the only
    // chat traffic that lands in the bell feed.
    'chat_first_message' => [
        'title' => 'Nouvelle discussion de :name',
        'body' => ':preview',
    ],
    'chat_group' => 'Discussion de groupe',
    'chat_new_message' => 'Nouveau message',
    'chat_attachment' => 'A envoyé une pièce jointe',
    'message_deleted' => 'Message supprimé',
    'message_photo' => '📷 Photo',
    'message_voice' => '🎤 Note vocale',
    'message_file' => '📎 Fichier',

    // La couche GPS du dispatch : refus d'affectation, affectation non
    // acceptée au-delà du délai, arrivée en retard sur site.
    'visit_declined' => [
        'title' => 'Visite refusée',
        'body' => ":agent a refusé la visite avec :client:extra — « :reason ».",
    ],
    'visit_unaccepted' => [
        'title' => "Affectation pas encore acceptée",
        'body' => ":agent n'a pas encore accepté la visite avec :client (:when).",
    ],
    'visit_late' => [
        'title' => 'Agent en retard',
        'body' => ":agent n'est pas encore arrivé pour la visite avec :client (:when).",
    ],
];
