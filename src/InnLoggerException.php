<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger;

use RuntimeException;

/**
 * Only thrown when fail_silent is false. The message never contains secrets or payloads.
 */
class InnLoggerException extends RuntimeException
{
}
