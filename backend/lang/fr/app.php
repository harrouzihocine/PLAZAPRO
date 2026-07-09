<?php

declare(strict_types=1);

return [
    'duplicate_own' => 'Un client avec ce téléphone existe déjà dans votre liste.',
    'duplicate_other' => 'Ce téléphone appartient déjà au client d’un autre utilisateur. Une demande a été envoyée à un superviseur.',
    // Import CSV des unités (erreurs par ligne renvoyées à l’importateur).
    'units_import_bad_header' => 'Fichier non reconnu : la première ligne doit nommer les colonnes (utilisez un CSV exporté comme modèle).',
    'units_import_unknown_id' => 'Aucune unité active avec l’id :id.',
    'units_import_unknown_project' => 'Projet « :project » inconnu.',
    'units_import_project_required' => 'Une nouvelle unité nécessite un projet (colonne location_id ou project).',
    'units_import_price_required' => 'Une nouvelle unité nécessite un prix.',
    'unit_needs_one_price' => 'Une unité doit garder au moins un prix (semi-fini ou fini).',
    'unit_correction_empty' => 'Rien à corriger — envoyez un prix ou un statut.',
    'units_import_unknown_item' => 'Valeur « :value » inconnue.',
    'units_import_duplicate_reference' => 'La référence « :reference » existe déjà dans ce projet.',
    'units_import_reason' => 'Import CSV par :user',
];
