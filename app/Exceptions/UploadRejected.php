<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an uploaded file or archive is refused. The message is safe to show to the user.
 */
class UploadRejected extends RuntimeException
{
}
