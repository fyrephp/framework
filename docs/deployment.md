# Deployment

Use this guide to prepare a FyreFramework application for production.

It covers the framework-facing parts of a deployment. Building images, provisioning servers,
switching releases, and rolling back infrastructure remain responsibilities of the application
and its hosting platform.

## Table of Contents

- [Start here](#start-here)
- [Install production dependencies](#install-production-dependencies)
- [Expose only the public directory](#expose-only-the-public-directory)
- [Load production configuration and secrets](#load-production-configuration-and-secrets)
- [Disable debug output and handle errors](#disable-debug-output-and-handle-errors)
- [Configure production logging](#configure-production-logging)
- [Prepare writable paths and services](#prepare-writable-paths-and-services)
- [Apply database migrations](#apply-database-migrations)
- [Configure HTTPS and trusted proxies](#configure-https-and-trusted-proxies)
- [Secure browser and authentication state](#secure-browser-and-authentication-state)
  - [Sessions and authentication cookies](#sessions-and-authentication-cookies)
  - [CSRF and CSP](#csrf-and-csp)
  - [CORS and rate limiting](#cors-and-rate-limiting)
- [Run queue workers](#run-queue-workers)
- [Add health checks](#add-health-checks)
- [Deployment checklist](#deployment-checklist)
- [Related](#related)

## Start here

Deploy the HTTP entry point, CLI entry point, application code, configuration, templates, and
Composer dependencies from the same application release. Web processes and queue workers must
load compatible code and configuration.

Before sending traffic to a release:

1. Install its locked production dependencies.
2. Load and validate its production configuration.
3. Prepare required storage and external services.
4. Apply database migrations through one deployment process.
5. Start or restart long-running workers.
6. Check the deployed HTTP application through a health endpoint.

For the application bootstrap and entry points this guide builds on, see
[Getting Started](getting-started.md).

## Install production dependencies

Commit the application repository's `composer.lock` and install from that lock so every process
uses the same dependency versions:

```bash
composer install --no-dev --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
```

The target runtime must provide PHP 8.6 or later and the required extensions. Install the
feature-specific optional extensions and external programs used by the application as well. For
example, database connections need PDO and a matching driver, queue workers need `ext-pcntl`,
and PDF rendering needs an executable Chrome or Chromium binary. See
[Requirements](getting-started.md#requirements) and [PDF](utilities/pdf.md#environment-requirements).

Build production dependencies as part of the release rather than allowing web requests or
workers to modify `vendor` at runtime.

## Expose only the public directory

Set the web server document root to the application's `public` directory. Keep configuration,
source code, templates, logs, temporary data, and `vendor` outside that directory.

Route requests for missing static files to `public/index.php`. Existing files should be served
directly by the web server. The Apache and Nginx examples in
[Web-server configuration](getting-started.md#web-server-configuration) show the minimal rewrite
rules.

Use the externally visible HTTPS URL for `App.baseUri` when the application generates absolute
URLs. If the application is mounted below the origin root, include that path in `App.baseUri`;
the router uses it for both request matching and URL generation.

## Load production configuration and secrets

Define the application's configuration paths during bootstrap and load production overrides
after shared defaults. `Config` processes paths in order, so values from later paths replace
earlier values recursively.

Keep production-only configuration outside the public directory. Do not commit credentials or
secret values. Common secrets include:

- database, Redis, Memcached, and SMTP credentials
- encryption keys
- `Csrf.salt`
- the `CookieAuthenticator` salt
- external API credentials and bearer tokens

Fyre does not prescribe an environment-file or secret-manager integration. The application
bootstrap is responsible for reading its deployment environment and placing those values into
`Config`. Because missing and non-array configuration files are ignored, validate settings that
must be present before accepting requests or starting workers.

See [Config](core/config.md) for path precedence, loading, and framework-owned configuration
keys.

## Disable debug output and handle errors

Disable debug output in production and keep error logging enabled:

```php
return [
    'App' => [
        'debug' => false,
    ],
    'Error' => [
        'log' => true,
    ],
];
```

With `App.debug` disabled, the default error renderer returns a generic `Internal Server Error`
body instead of the exception and stack trace. If the application supplies `Error.renderer`,
that renderer is responsible for avoiding disclosure of exception details, configuration,
credentials, queries, and filesystem paths.

Place `error` middleware near the start of the HTTP middleware queue so exceptions from later
middleware and route handling use the configured renderer. Error logging also needs at least
one usable `Log` handler; see [HTTP Middleware](http/middleware.md) and
[Logging](logging/index.md).

## Configure production logging

Send application and error logs to durable storage or a process stream collected by the hosting
platform. When using `FileLogger`, set an application-owned `path` instead of relying on the
system temporary directory, and configure permissions and external retention appropriate for
the deployment.

Avoid the special `{get_vars}`, `{post_vars}`, `{server_vars}`, `{session_vars}`, and
`{backtrace}` placeholders in production logs unless the resulting data is known to be safe.
Also review application log context for passwords, cookies, authorization headers, personal
data, and other secrets.

Fyre suppresses file write failures after a `FileLogger` has been constructed. Verify log
delivery as part of deployment monitoring rather than assuming a request will fail when logging
is unavailable.

## Prepare writable paths and services

Create application-owned directories before starting PHP and grant only the web and worker
processes the access they need. The relevant storage depends on the selected handlers:

| Feature | Production requirement |
| --- | --- |
| file cache | writable `Cache.<name>.path` directory |
| file logging | writable `Log.<name>.path` directory |
| file sessions | writable `Session.path` directory |
| PHP and framework temporary files | writable system temporary directory |
| Redis or Memcached handlers | reachable, access-controlled service with the intended database or key prefix |
| Redis queue | reachable, protected Redis service; there is no local queue path |

File handlers can create their configured directory when the parent is writable, but creating
it explicitly makes ownership and permission failures visible during deployment. Do not rely on
system temporary storage for data that must survive host restarts or release cleanup.

When the application runs on more than one host, local cache and session files are not shared.
Choose a shared handler when requests or workers need consistent state across those hosts.
Treat Redis queue data and serialized messages as trusted infrastructure and restrict access to
the queue backend.

See [Cache](cache/index.md), [Sessions](http/sessions.md), [Logging](logging/index.md), and
[Queue](queue/index.md) for handler-specific options.

## Apply database migrations

Inspect and apply migrations from a single deployment process rather than from every web or
worker process:

```bash
bin/console db:status --db=default
bin/console db:migrate --dry-run --db=default
bin/console db:migrate --db=default
```

The migration runner uses a database lock and recalculates its plan after acquiring it. The
lock protects against overlapping migration runners, but the release process should still have
one clear migration owner.

Back up data before changes that need a recovery point. Migrations and generated DDL are not
automatically transactional, so design changes to tolerate partial failure and test the
recovery path. Set `--lock-expires` longer than the longest individual migration because the
lease is refreshed between migrations, not during one.

Run `db:status` again after migration and investigate any `missing` entries. See
[Database migrations](database/migrations.md) for planning, locking, history, and rollback
behavior.

## Configure HTTPS and trusted proxies

Serve production traffic over HTTPS. Set secure cookie options and use an HTTPS `App.baseUri`.
Transport security, certificate renewal, redirects from HTTP, and headers such as HSTS normally
belong at the web server or trusted reverse proxy.

When a trusted proxy terminates TLS or supplies client-address headers, configure:

```php
return [
    'App' => [
        'trustProxy' => true,
        'trustedProxies' => [
            '127.0.0.1',
        ],
    ],
];
```

List the actual proxy addresses that may provide forwarded headers. Enabling proxy trust with
an empty `App.trustedProxies` list accepts the rightmost forwarded address, which is unsuitable
when untrusted clients can send those headers directly.

Verify `ServerRequest::isSecure()` and `getClientIp()` through the deployed proxy path. These
values can affect URL generation, auditing, and IP-based rate limits. See
[HTTP Requests](http/requests.md#inspecting-request-context).

## Secure browser and authentication state

Enable only the middleware and authentication mechanisms the application uses, and verify their
production options before traffic is switched.

### Sessions and authentication cookies

Keep the session cookie restricted to HTTPS and select a `SameSite` value appropriate for the
application. Fyre enables HTTP-only session cookies and strict session mode; the default session
cookie is also `Secure`.

For `CookieAuthenticator`, explicitly set `cookieOptions.secure` to `true` for HTTPS and use a
stable secret `salt`. The authenticator's default cookie options enable `HttpOnly`, but do not
enable `Secure`. Review the cookie domain, path, lifetime, and `SameSite` behavior as well.

Prefer bearer-token request headers over query-string tokens. Query parameters can be recorded
in URLs, logs, browser history, and referrer headers. See [Sessions](http/sessions.md) and
[Authentication](auth/authentication.md).

### CSRF and CSP

Enable CSRF middleware for browser requests that use cookies for state. Load a stable secret
`Csrf.salt`, keep its cookie secure over HTTPS, and narrowly scope any `skipCheck` callback.

Configure CSP for rendered pages and apply it with `csp` middleware. A new policy can begin in
report-only mode so violations can be observed before enforcement. Ensure configured reporting
endpoints are prepared to receive reports without exposing sensitive diagnostics.

See [CSRF](security/csrf.md) and [Content Security Policy](security/csp.md).

### CORS and rate limiting

If browser clients access the application from another origin, register `CorsMiddleware` with an
explicit allowlist. Place it before authentication, CSRF, and routing so it can handle preflight
`OPTIONS` requests. Do not treat CORS as authentication or authorization, and do not combine a
wildcard emitted origin with credentialed browser requests.

Apply rate limiting to public or costly endpoints using a shared production cache so limits are
consistent across application hosts. If limits use client IPs, configure trusted proxies first.
See [CORS](security/cors.md), [HTTP Middleware](http/middleware.md), and
[Rate Limiting](security/rate-limiting.md).

## Run queue workers

Run `queue:worker` under a process supervisor rather than an interactive shell:

```bash
bin/console queue:worker --config=default --queue=default --max-runtime=3600
```

The worker requires `ext-pcntl`. Configure `maxJobs` or `maxRuntime` so the supervisor rotates
workers periodically, and restart workers during deployment so they load the new release's code
and configuration. Stop signals are handled gracefully after the current job completes.

Set the Redis `visibilityTimeout` longer than the longest expected job. Use explicit timeouts for
external calls because the worker does not impose a per-job timeout. Jobs may run more than once,
so make their effects idempotent.

Monitor `queue:stats` and retained failures, and run separate worker pools when queues have
different latency, resource, or concurrency requirements. See [Queue Worker](queue/worker.md).

## Add health checks

The framework does not register a health endpoint automatically. Add an application route that
can be called by the load balancer or runtime platform:

```php
$router->get(
    'health',
    static fn(): string => 'ok',
    as: 'health'
);
```

Keep a liveness check cheap and independent of optional external services. Use a separate
readiness check when traffic should wait for critical dependencies such as the primary database
or cache, and apply short timeouts to those checks.

Do not require a browser session or user authentication, and do not expose configuration,
credentials, exception details, or detailed infrastructure state. Register the route before
broad dynamic routes when route order could otherwise shadow it.

## Deployment checklist

- Install the exact dependencies from the application lock file without development packages.
- Confirm the target PHP version and required extensions.
- Expose only `public` through the web server.
- Load and validate production configuration and secrets outside the public directory.
- Set `App.debug` to `false` and verify safe error rendering and log delivery.
- Prepare writable file paths and connectivity to database, cache, session, mail, and queue services.
- Preview, apply, and verify database migrations through one deployment process.
- Verify HTTPS, `App.baseUri`, trusted proxies, and browser cookie settings.
- Enable and configure the required auth, CSRF, CSP, CORS, and rate-limit controls.
- Start or restart supervised queue workers from the deployed release.
- Check liveness and readiness before sending production traffic.

## Related

- [Getting Started](getting-started.md)
- [Config](core/config.md)
- [HTTP Middleware](http/middleware.md)
- [HTTP Requests](http/requests.md)
- [Sessions](http/sessions.md)
- [Authentication](auth/authentication.md)
- [Security](security/index.md)
- [Database migrations](database/migrations.md)
- [Cache](cache/index.md)
- [Logging](logging/index.md)
- [Queue](queue/index.md)
- [Queue Worker](queue/worker.md)
