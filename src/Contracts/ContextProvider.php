<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Contracts;

/**
 * Supplies ambient context (request, user, host) for every event.
 */
interface ContextProvider
{
    /**
     * May return any of: request_id, user_id, url, http_method, http_status,
     * hostname, metadata (array). Must never return passwords, Authorization
     * headers, cookies or request bodies. Implementations must not throw.
     *
     * @return array<string, mixed>
     */
    public function context(): array;
}
