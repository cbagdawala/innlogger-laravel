<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Laravel;

use Cbagdawala\InnLogger\Contracts\ContextProvider;
use Cbagdawala\InnLogger\Uuid;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Throwable;

/**
 * Captures route, HTTP method, URL (path + query, redacted later), status code,
 * request ID, authenticated user ID, hostname and application version.
 *
 * Never reads request bodies, Authorization headers or cookies. The user ID is
 * only read when a guard has already resolved the user, so logging never
 * triggers an extra authentication lookup or database query.
 */
final class LaravelContextProvider implements ContextProvider
{
    public const REQUEST_ID_ATTRIBUTE = 'innlogger.request_id';
    public const STATUS_ATTRIBUTE = 'innlogger.http_status';

    public function __construct(
        private readonly Application $app,
        private readonly bool $captureRequest = true,
        private readonly bool $captureUser = true,
        private readonly ?string $hostname = null,
        private readonly ?string $applicationVersion = null,
    ) {
    }

    public function context(): array
    {
        $context = ['metadata' => []];

        $hostname = $this->hostname ?? (gethostname() ?: null);
        if ($hostname !== null) {
            $context['hostname'] = $hostname;
        }

        if ($this->applicationVersion !== null) {
            $context['metadata']['application_version'] = $this->applicationVersion;
        }

        try {
            if ($this->captureRequest) {
                $this->request($context);
            }
        } catch (Throwable) {
            // Context capture is best effort.
        }

        try {
            if ($this->captureUser) {
                $this->user($context);
            }
        } catch (Throwable) {
            // Context capture is best effort.
        }

        if ($context['metadata'] === []) {
            unset($context['metadata']);
        }

        return $context;
    }

    public static function requestId(Request $request): string
    {
        $existing = $request->attributes->get(self::REQUEST_ID_ATTRIBUTE);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $header = (string) ($request->headers->get('X-Request-Id') ?? $request->headers->get('X-Correlation-Id') ?? '');
        $id = preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $header) === 1 ? $header : Uuid::v4();
        $request->attributes->set(self::REQUEST_ID_ATTRIBUTE, $id);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function request(array &$context): void
    {
        if (! $this->app->bound('request')) {
            return;
        }

        $request = $this->app->make('request');
        if (! $request instanceof Request) {
            return;
        }

        $route = $request->route();
        // In the console there is no dispatched route: skip the synthetic CLI request.
        if ($this->app->runningInConsole() && $route === null) {
            return;
        }

        $context['request_id'] = self::requestId($request);
        $context['http_method'] = $request->getMethod();
        $context['url'] = $request->getRequestUri();

        $status = $request->attributes->get(self::STATUS_ATTRIBUTE);
        if (is_int($status)) {
            $context['http_status'] = $status;
        }

        if (is_object($route)) {
            $name = method_exists($route, 'getName') ? $route->getName() : null;
            $uri = method_exists($route, 'uri') ? $route->uri() : null;
            $routeLabel = $name ?: ($uri !== null ? '/'.ltrim((string) $uri, '/') : null);
            if ($routeLabel !== null) {
                $context['metadata']['route'] = $routeLabel;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function user(array &$context): void
    {
        if (! $this->app->resolved('auth')) {
            return;
        }

        $auth = $this->app->make('auth');
        if (method_exists($auth, 'hasResolvedGuards') && ! $auth->hasResolvedGuards()) {
            return;
        }

        $guard = $auth->guard();
        if (! method_exists($guard, 'hasUser') || ! $guard->hasUser()) {
            return;
        }

        $id = $guard->id();
        if (is_int($id) || (is_string($id) && $id !== '')) {
            $context['user_id'] = $id;
        }
    }
}
