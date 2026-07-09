<?php

declare(strict_types=1);

// Small user-facing API messages (fr/ar mirror this file).
return [
    'duplicate_own' => 'A client with this phone already exists in your list.',
    'duplicate_other' => 'This phone already belongs to another user’s client. A request was sent to a supervisor.',
    // CSV unit import (row-level errors reported back to the importer).
    'units_import_bad_header' => 'Unrecognized file: the first line must name the columns (use an exported CSV as the template).',
    'units_import_unknown_id' => 'No active unit with id :id.',
    'units_import_unknown_project' => 'Unknown project ":project".',
    'units_import_project_required' => 'A new unit needs a project (location_id or project column).',
    'units_import_price_required' => 'A new unit needs a price.',
    'unit_needs_one_price' => 'A unit must keep at least one price (semi-fini or fini).',
    'unit_correction_empty' => 'Nothing to correct — send a price or a status.',
    'units_import_unknown_item' => 'Unknown value ":value".',
    'units_import_duplicate_reference' => 'Reference ":reference" already exists in this project.',
    'units_import_reason' => 'CSV import by :user',
];
