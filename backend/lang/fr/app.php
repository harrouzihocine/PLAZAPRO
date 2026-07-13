<?php

declare(strict_types=1);

return [
    'duplicate_own' => 'Un client avec ce téléphone existe déjà dans votre liste.',
    'duplicate_other' => 'Ce téléphone appartient déjà au client d’un autre utilisateur. Une demande a été envoyée à un superviseur.',
    // Import Excel des unités (erreurs par ligne renvoyées à l’importateur).
    'units_import_bad_header' => 'Fichier non reconnu : la première ligne doit nommer les colonnes (téléchargez le modèle d’import ou partez d’un export).',
    'units_import_unknown_id' => 'Aucune unité active avec l’id :id.',
    'units_import_unknown_project' => 'Projet « :project » inconnu.',
    'units_import_project_required' => 'Une nouvelle unité nécessite un projet (colonne location_id ou project).',
    'units_import_price_required' => 'Une nouvelle unité nécessite un prix.',
    'unit_needs_one_price' => 'Une unité doit garder au moins un prix (semi-fini ou fini).',
    'unit_correction_empty' => 'Rien à corriger — envoyez un prix ou un statut.',
    'units_import_unknown_item' => 'Valeur « :value » inconnue.',
    'units_import_duplicate_reference' => 'La référence « :reference » existe déjà dans ce projet.',
    'units_import_reason' => 'Import Excel par :user',
    // L’export .xlsx des unités / le modèle d’import (noms d’onglets + onglet Guide).
    'units_sheet_units' => 'Unités',
    'units_sheet_guide' => 'Guide',
    'units_guide_note' => 'Chaque ligne est une unité. Remplacez les lignes d’exemple grises par vos données, puis importez le fichier depuis la page Unités. Laissez « reference » vide pour la générer automatiquement. Les lignes portant une colonne « id » (issues d’un export) mettent à jour l’unité au lieu d’en créer une ; le statut de vente n’est jamais modifié par un import.',
    'units_guide_column' => 'Colonne',
    'units_guide_required' => 'Obligatoire',
    'units_guide_description' => 'Description',
    'units_guide_allowed' => 'Valeurs autorisées',
    'units_guide_yes' => 'Oui',
    'units_guide_no' => 'Non',
    'units_guide_one_price' => 'Au moins un des deux prix',
    'units_guide_number' => 'Nombre',
    'units_guide_auto' => 'Texte libre — laisser vide pour la générer automatiquement',
    'units_guide_project' => 'Le projet auquel appartient l’unité — doit correspondre exactement à un nom de projet existant.',
    'units_guide_reference' => 'Référence de l’unité, unique dans son projet.',
    'units_guide_rooms' => 'Type (F2, F3…), tel que configuré dans les paramètres.',
    'units_guide_floor' => 'Étage, tel que configuré dans les paramètres.',
    'units_guide_area_sqm' => 'Surface habitable en m².',
    'units_guide_price_semi_fini' => 'Prix semi-fini, en DZD.',
    'units_guide_price_fini' => 'Prix fini, en DZD.',
    'units_guide_gtm_priority' => 'Priorité commerciale (vide = medium).',
    'units_guide_block' => 'Bloc / entrée du bâtiment.',
    'units_guide_stack_floor' => 'Numéro d’étage utilisé par le plan d’empilement.',
    'units_guide_position' => 'Position sur l’étage (plan d’empilement).',
    'units_example_project' => 'Projet exemple — à remplacer',

    // Partage de médias (envoi WhatsApp au client)
    'media_share_bad_items' => 'Certains éléments sélectionnés ne peuvent plus être partagés (supprimés, ou ni photos/vidéos/plans).',
];
