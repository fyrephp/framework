# Authentication

Use `Auth` to coordinate authenticators, resolve the current user, and manage login, logout, and session-based impersonation consistently across the request lifecycle.

This page covers authenticator configuration, request flow, identity management, and the `Auth` and `Identifier` APIs you will use most.

## Table of Contents

- [Start here](#start-here)
- [Authentication flow](#authentication-flow)
- [Configuring authenticators](#configuring-authenticators)
- [Common setups](#common-setups)
  - [Session-only (typical HTML app)](#session-only-typical-html-app)
  - [Session + cookie “remember me”](#session--cookie-remember-me)
  - [Token auth (typical JSON API)](#token-auth-typical-json-api)
- [Built-in authenticators](#built-in-authenticators)
  - [`SessionAuthenticator`](#sessionauthenticator)
  - [`CookieAuthenticator`](#cookieauthenticator)
  - [`TokenAuthenticator`](#tokenauthenticator)
- [Logging in and out](#logging-in-and-out)
  - [Attempting a credential login](#attempting-a-credential-login)
  - [Logging in a known user](#logging-in-a-known-user)
  - [Logging out](#logging-out)
- [Impersonating users](#impersonating-users)
  - [Starting impersonation](#starting-impersonation)
  - [Resolving the original user](#resolving-the-original-user)
  - [Stopping impersonation](#stopping-impersonation)
- [Resolving the current user](#resolving-the-current-user)
- [Building the login URL](#building-the-login-url)
- [Identifying users with Identifier](#identifying-users-with-identifier)
- [Related](#related)

## Start here

Use `Auth` when you want to:

- attempt a login using credentials (via `Identifier`)
- log in or log out a known user
- temporarily impersonate another user in session-authenticated applications
- check whether a user is logged in
- retrieve the current user entity

`Auth` also coordinates authenticators that resolve request identities and persist them between requests. Session and cookie identities are stateful, while token identities apply only to the request that supplied the token. Once a user has been resolved, `Auth` also provides `access()` as the authorization entry point for that user; see [Authorization](authorization.md).

In a typical HTTP app:

1. Configure one or more authenticators (for example, session + cookie).
2. Register the `auth` middleware alias in your global middleware queue, usually after `session`.
3. Read the current user from the request (or from helpers), use auth middleware for route guards, and use `Auth::access()` for authorization checks inside handlers or actions.

If you haven’t set up middleware yet, start with [Auth Middleware](middleware.md).

## Authentication flow

On a normal HTTP request, authentication usually happens in middleware:

1. `AuthMiddleware` adds the `auth` request attribute (the `Auth` instance).
2. `Auth::authenticate()` executes authenticators in order until one returns a user (first match wins).
3. On success, the resolved user becomes current in `Auth`. Stateful identities are also passed to the configured authenticators for persistence.
4. The `user` request attribute is added (the resolved user, or `null`).
5. After the downstream handler returns, `beforeResponse()` is called on all authenticators with the current user from `Auth`.

For details on the HTTP middleware and route-level guards, see [Auth Middleware](middleware.md).

## Configuring authenticators

Authenticators are configured in the `Auth` config section, under the `authenticators` key. Each entry must specify a `className` that extends `Authenticator`. Remaining options are forwarded to the authenticator constructor.

For configuration basics, see [Config](../core/config.md).

`Auth.loginRoute` controls where unauthenticated HTML requests are redirected by middleware. This value is a *route alias* (the `as` name), not a URL path; see [Router aliases](../routing/router.md#aliases-and-url-generation). If not configured, it defaults to `login`.

If you need custom authentication behavior, create your own authenticator class and add it to the stack alongside the built-in ones. Implement `StatelessAuthenticatorInterface` when a resolved identity must apply only to the current request. Implement `ImpersonationAuthenticatorInterface` when the authenticator can safely support impersonation.

## Common setups

Most applications use one of these configurations. Authenticators run in order and stop at the first one that returns a user, so order them by intended precedence. For example, use session before cookie in a browser app, and use a token authenticator by itself for a typical API.

### Session-only (typical HTML app)

If you’re building a traditional HTML app, session-based authentication is usually enough:

```php
use Fyre\Auth\Authenticators\SessionAuthenticator;

return [
    'Auth' => [
        'authenticators' => [
            [
                'className' => SessionAuthenticator::class,
            ],
        ],
    ],
];
```

### Session + cookie “remember me”

If you want “remember me”, add a cookie authenticator after the session authenticator so the session remains the primary source when present:

```php
use Fyre\Auth\Authenticators\CookieAuthenticator;
use Fyre\Auth\Authenticators\SessionAuthenticator;

return [
    'Auth' => [
        'authenticators' => [
            [
                'className' => SessionAuthenticator::class,
            ],
            [
                'className' => CookieAuthenticator::class,
                'cookieName' => 'auth',
                'cookieOptions' => [
                    'expires' => 2_592_000,
                    'secure' => true,
                ],
            ],
        ],
    ],
];
```

This keeps the cookie for 30 days and restricts it to HTTPS. For local development over plain HTTP, set `secure` to `false` or the browser will not send it.

### Token auth (typical JSON API)

For APIs, configure a token authenticator and ensure your clients send the header you’ve configured:

```php
use Fyre\Auth\Authenticators\TokenAuthenticator;

return [
    'Auth' => [
        'authenticators' => [
            [
                'className' => TokenAuthenticator::class,
                'tokenHeader' => 'Authorization',
                'tokenHeaderPrefix' => 'Bearer',
            ],
        ],
    ],
];
```

Token authentication is stateless. A successful token does not create or update session or cookie authentication state, even when those authenticators are also configured.

## Built-in authenticators

Fyre includes three built-in authenticators. Each authenticator receives its listed options alongside `className`.

### `SessionAuthenticator`

Reads an identity value from the session and loads the user from the model configured by `Identifier`.

- `sessionKey` (`string`): the session key used to store the identity (default: `'auth'`).
- `sessionField` (`string`): the user field stored in the session and used for lookup (default: `'id'`).
- `impersonatorSessionKey` (`string`): the session key used to store the original identity during impersonation (default: `'authImpersonator'`).

When login changes the stored identity, the authenticator rotates the session ID before storing the new value. Logout removes the session value and rotates the session ID again.

`sessionKey` and `impersonatorSessionKey` must be different. `SessionAuthenticator` is the only built-in authenticator that supports user impersonation. The current request must have been authenticated by it before impersonation can start.

### `CookieAuthenticator`

Reads a remember-me cookie and validates it against the stored user. Login can queue a cookie for the next response, while logout or an invalid payload queues it for deletion. A queued cookie is always generated from the user passed to `login()`, even if the current user changes before the response is sent.

- `cookieName` (`string`): the cookie name (default: `'auth'`).
- `cookieOptions` (`array<string, mixed>`): options passed to `Cookie` (default: `['httpOnly' => true]`).
- `identifierField` (`string`): the user field stored in the cookie (default: `'email'`).
- `passwordField` (`string`): the password-hash field (default: `'password'`). Include it in custom identity queries. Issuing and verifying remember-me cookies requires a non-empty string in this field. A missing or empty hash clears the cookie.
- `salt` (`string|null`): an optional secret HMAC key included when deriving the cookie token (default: `null`).

Within `CookieAuthenticator`, `cookieOptions.expires` is a lifetime in seconds and is converted to an absolute expiry when the cookie is written. If it is omitted, the result is a browser-session cookie. Set `cookieOptions.secure` to `true` when the application uses HTTPS.

Cookie authentication is stateful. When `SessionAuthenticator` is also configured, a successful remember-me cookie establishes the session identity used on following requests.

For remember-me cookies, configure `salt` with a stable application secret. Changing the configured identifier field, password hash, or salt invalidates existing cookies; invalid payloads are queued for deletion on the next response.

Cookie token hashes must match `PASSWORD_DEFAULT` and its default options before verification. Hashes with a different algorithm or work factor are rejected and queued for deletion. A PHP upgrade that changes these defaults invalidates existing remember-me cookies.

### `TokenAuthenticator`

Loads the user by a token read from a request header or query parameter.

- `tokenHeader` (`string|null`): the request header to inspect (default: `'Authorization'`).
- `tokenHeaderPrefix` (`string|null`): the prefix stripped from the header value (default: `'Bearer'`).
- `tokenQuery` (`string|null`): the fallback query parameter (default: `null`).
- `tokenField` (`string`): the user field matched against the token (default: `'token'`).

A non-empty header token takes precedence, even if it does not identify a user. The configured query parameter is used when header lookup is disabled, the header is absent, or its token is empty after optional prefix removal.

`TokenAuthenticator` implements `StatelessAuthenticatorInterface`. Its resolved user is available through `Auth` and the request `user` attribute for the current request, but is not persisted by other authenticators and cannot start impersonation.

Prefer a request header for bearer tokens. Query-string tokens can be exposed through URLs, logs, browser history, and referrer headers; enable `tokenQuery` only when the client cannot send a suitable header.

## Logging in and out

There are two common ways to set the current user on `Auth`.

Examples below assume you have an `Auth` instance in `$auth`. You can resolve it from the container or use the `auth()` helper:

```php
use Fyre\Auth\Auth;

$auth = app(Auth::class);
// or
$auth = auth();
```

### Attempting a credential login

`Auth::attempt(string $identifier, string $password, bool $rememberMe = false)` delegates credential verification to `Identifier` and logs the user into `Auth` on success. It returns the resolved user, or `null` when either value is empty or the credentials are invalid.

```php
$user = $auth->attempt($login, $password, rememberMe: true);

if (!$user) {
    // invalid credentials
}
```

### Logging in a known user

When you already have an identity entity, call `login(Entity $user, bool $rememberMe = false)` directly. This updates the current in-memory user immediately, then notifies every authenticator so it can persist the identity. `CookieAuthenticator` writes its cookie only when `$rememberMe` is `true`.

```php
$auth->login($user);
```

### Logging out

`logout()` clears the current user and notifies every authenticator so it can clear persisted state. This also clears any active impersonation:

```php
$auth->logout();
```

## Impersonating users

Impersonation temporarily replaces the effective user while retaining the original user so it can be restored. It is intended for controlled administrative or support workflows and requires the current request identity to have been resolved by `SessionAuthenticator`.

The application is responsible for authorizing the operation. Always perform the authorization check before starting impersonation, because authorization uses the current effective user.

An identity resolved by `TokenAuthenticator` cannot impersonate another user. Calling `login()` or `attempt()` directly also does not establish an impersonation-capable authentication source. Redirect first, then start impersonation from the subsequent session-authenticated request.

### Starting impersonation

Call `Auth::impersonate(Entity $user): static` with the user that should become the effective identity:

```php
$auth->access()->authorize('impersonate', $targetUser);
$auth->impersonate($targetUser);

return $response->withLocation('/dashboard');
```

The session ID is rotated, the original user identifier is stored under `impersonatorSessionKey`, and the target identifier replaces the value under `sessionKey`.

The target becomes the current user on the next request. Redirect immediately after calling `impersonate()` so `Auth::user()` and the immutable request `user` attribute continue to describe the same identity. A user cannot impersonate themselves, and nested impersonation is not supported.

### Resolving the original user

While `Auth::isImpersonating(): bool` returns `true`, `Auth::user(): Entity|null` returns the effective user and `Auth::impersonator(): Entity|null` returns the original user:

```php
if ($auth->isImpersonating()) {
    $effectiveUser = $auth->user();
    $originalUser = $auth->impersonator();
}
```

Use both values when audit records need to distinguish who performed an action from whose identity was active. Authorization through `Auth::access()` continues to use the effective user.

### Stopping impersonation

Call `Auth::stopImpersonating(): static` to restore the original session identity:

```php
$auth->stopImpersonating();

return $response->withLocation('/admin/users');
```

The session ID is rotated and the original user becomes current on the next request. Calling `stopImpersonating()` when impersonation is not active has no effect.

Remember-me cookies are not changed by impersonation. They remain associated with the user originally passed to `login(..., rememberMe: true)`, so expiration of the impersonation session cannot authenticate the target through that cookie.

## Resolving the current user

From the `Auth` service, use these methods:

- `user(): Entity|null`
- `isLoggedIn(): bool`
- `impersonator(): Entity|null`
- `isImpersonating(): bool`

Global helper alternatives:

- `user(): Entity|null`
- `logged_in(): bool`

In HTTP requests, `AuthMiddleware` adds both `auth` and `user` to the request attributes:

```php
$auth = $request->getAttribute('auth');
$user = $request->getAttribute('user');
```

## Building the login URL

If you need to generate the login URL for redirects, `Auth::getLoginUrl()` uses the configured login route and can include a redirect target via the `url` query parameter. When you pass a `UriInterface`, the generated redirect value keeps only the path, query string, and fragment.

- `getLoginUrl(string|UriInterface|null $redirect = null): string`

```php
$loginUrl = $request->getUri() |> $auth->getLoginUrl(...);
```

## Identifying users with Identifier

`Identifier` is responsible for locating a user record and verifying the password hash.

In most applications, you’ll access it via `Auth::identifier()` (or `auth()->identifier()`):

- `$identifier = $auth->identifier();`

Commonly used methods:

- `attempt(string $identifier, string $password): Entity|null`
- `identify(string $identifier): Entity|null`
- `identifyBy(array $fields, mixed $value): Entity|null` — finds the first user matching the value in any of the supplied fields
- `getIdentifierFields(): array`
- `getModel(): Model`
- `getPasswordField(): string`

`Identifier` reads options from the `Auth` config section, under the `identifier` key, with defaults:

- `identifierFields` (default `['email']`) — fields matched using an `or` condition when multiple fields are configured
- `passwordField` (default `'password'`)
- `modelAlias` (default `'Users'`)
- `queryCallback` (default `null`) — optional callback applied after the lookup conditions for password, remember-me, session, and bearer-token authentication. A filter here can exclude a suspended account even when it has an existing session or token.

`Identifier::attempt()` returns `null` if either the identifier or password is empty, or if the credentials do not match. On successful login, it also upgrades and persists the stored password hash when rehashing is needed.

## Related

- [Auth Middleware](middleware.md)
- [Authorization](authorization.md)
- [Config](../core/config.md)
- [Helpers](../core/helpers.md)
