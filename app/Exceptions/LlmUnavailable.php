<?php

namespace App\Exceptions;

use RuntimeException;

/** The AI service is rate limited, unreachable or rejected our key: stop using it for this run. */
class LlmUnavailable extends RuntimeException
{
}
