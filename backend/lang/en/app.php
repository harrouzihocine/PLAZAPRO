<?php

declare(strict_types=1);

// Small user-facing API messages (fr/ar mirror this file).
return [
    'duplicate_own' => 'A client with this phone already exists in your list.',
    'duplicate_other' => 'This phone already belongs to another user’s client. A request was sent to a supervisor.',
    // Excel unit import (row-level errors reported back to the importer).
    'units_import_bad_header' => 'Unrecognized file: the first line must name the columns (download the import template or start from an export).',
    'units_import_unknown_id' => 'No active unit with id :id.',
    'units_import_unknown_project' => 'Unknown project ":project".',
    'units_import_project_required' => 'A new unit needs a project (location_id or project column).',
    'units_import_price_required' => 'A new unit needs a price.',
    'unit_needs_one_price' => 'A unit must keep at least one price (semi-fini or fini).',
    'unit_correction_empty' => 'Nothing to correct — send a price or a status.',
    'units_import_unknown_item' => 'Unknown value ":value".',
    'units_import_duplicate_reference' => 'Reference ":reference" already exists in this project.',
    'units_import_reason' => 'Excel import by :user',
    'units_import_archived_reason' => 'Absent from the latest import',
    // The units .xlsx export / import template (sheet names + Guide sheet).
    'units_sheet_units' => 'Units',
    'units_sheet_guide' => 'Guide',
    'units_guide_note' => 'Each row is one unit. Replace the grey example rows with your data, then import the file on the Units page. References are generated automatically. Rows carrying an "id" column (from an export) update that unit instead of creating a new one; sale status is never changed by an import.',
    'units_guide_column' => 'Column',
    'units_guide_required' => 'Required',
    'units_guide_description' => 'Description',
    'units_guide_allowed' => 'Allowed values',
    'units_guide_yes' => 'Yes',
    'units_guide_no' => 'No',
    'units_guide_one_price' => 'At least one of the two prices',
    'units_guide_number' => 'Number',
    'units_guide_auto' => 'Free text — leave empty to auto-generate',
    'units_guide_project' => 'The project the unit belongs to — must match an existing project name exactly.',
    'units_guide_reference' => 'Unit reference, unique within its project.',
    'units_guide_rooms' => 'Rooms type, as configured in Settings.',
    'units_guide_floor' => 'Floor label, as configured in Settings.',
    'units_guide_area_sqm' => 'Living area in m².',
    'units_guide_price_semi_fini' => 'Semi-finished price, in DZD.',
    'units_guide_price_fini' => 'Finished price, in DZD.',
    'units_guide_gtm_priority' => 'Sales push priority (empty = medium).',
    'units_guide_block' => 'Building block / entrance.',
    'units_guide_stack_floor' => 'Floor number used by the stacking plan.',
    'units_guide_position' => 'Position on the floor (stacking plan).',
    'units_guide_payment_methods' => 'Payment options for this unit. Leave empty to inherit the project\'s; list options (comma-separated) to override for this unit (e.g. cash-only).',
    'units_guide_unit_note' => 'Free-text note shown wherever the unit appears.',
    'units_example_project' => 'Example project — replace me',
    'units_example_note' => 'Corner unit, extra storage',

    // Media share (WhatsApp send-to-client)
    'media_share_bad_items' => 'Some selected items can no longer be shared (removed, or not photos/videos/plans).',
];
