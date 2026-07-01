<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;

/**
 * Thrown whenever code attempts to hard-delete a record. In PLAZA PRO records
 * are cancelled (status = cancelled + reason), never removed. See BaseModel.
 */
class RecordDeletionException extends RuntimeException {}
