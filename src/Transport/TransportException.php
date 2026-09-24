<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Transport;

use RuntimeException;

/**
 * No HTTP response was received (connection refused, DNS, TLS, timeout...).
 * Messages must never contain request headers, payloads or secrets.
 */
class TransportException extends RuntimeException
{
}
