<?php

declare(strict_types=1);

namespace App\Modules\Clients\Enums;

/**
 * The identity document presented when closing a deal (contract paperwork):
 * national ID card, driving licence or passport.
 */
enum IdDocumentType: string
{
    case NationalId = 'national_id';
    case DrivingLicense = 'driving_license';
    case Passport = 'passport';
}
