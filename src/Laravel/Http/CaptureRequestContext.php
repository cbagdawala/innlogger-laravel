<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Laravel\Http;

use Cbagdawala\InnLogger\Laravel\LaravelContextProvider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Optional middleware: assigns a request ID (from X-Request-Id or a new UUID)
 * before the request runs and records the response status code, so events
 * logged later in the request (or while terminating) carry both.
 */
final class CaptureRequestContext
{
    public function handle(Request $request, Closure $next): mixed
    {
        try {
            LaravelContextProvider::requestId($request);
        } catch (Throwable) {
            // never break the request
        }

        $response = $next($request);

        try {
            if ($response instanceof Response) {
                $request->attributes->set(LaravelContextProvider::STATUS_ATTRIBUTE, $response->getStatusCode());
            }
        } catch (Throwable) {
            // never break the request
        }

        return $response;
    }
}
