# HTTP Client

Use `Fyre\Http\Client` when your application needs to call external APIs, webhooks, or internal HTTP services.

It gives you convenient verb methods, an in-memory cookie jar, optional redirect following, and a configurable transport handler.

## Table of Contents

- [Start here](#start-here)
- [Making requests](#making-requests)
  - [Sending JSON](#sending-json)
  - [Sending prepared requests](#sending-prepared-requests)
- [Configuration](#configuration)
- [Redirects and cookies](#redirects-and-cookies)
  - [Redirect behavior](#redirect-behavior)
  - [Cookie behavior](#cookie-behavior)
- [Working with responses](#working-with-responses)
  - [Check status and read headers](#check-status-and-read-headers)
  - [Decode JSON responses](#decode-json-responses)
  - [Read response cookies](#read-response-cookies)
- [Handlers](#handlers)
  - [cURL handler](#curl-handler)
  - [Mock handler](#mock-handler)
  - [Custom handlers](#custom-handlers)
- [Testing](#testing)
- [Behavior notes](#behavior-notes)
- [Related](#related)

## Start here

The usual workflow is:

1. create a `Client`
2. make requests with `get()`, `post()`, and the other verb methods
3. inspect the returned `Client\Response`

## Making requests

Create a `Client`, then use `get()`, `post()`, `put()`, `patch()`, `delete()`, `head()`, `options()`, or `trace()`. Each method accepts an absolute URL, or a relative URL when `baseUrl` is configured. Per-request options override the client defaults for that call.

```php
use Fyre\Http\Client;

$client = new Client([
    'baseUrl' => 'https://api.example.com',
]);

$response = $client->get('/users', [
    'page' => 2,
]);

$users = $response->getJson();
```

Keep a single `Client` instance when you want cookies and default options to carry across multiple requests.

For `GET`, array data becomes query parameters and string data is parsed as a query string before it is merged with the URL. For the other methods, array data is encoded according to `Content-Type` and string data becomes the raw request body.

### Sending JSON

When you pass array `$data` to non-`GET` requests, the request body encoding depends on the request `Content-Type`. To send JSON, set a `Content-Type` header starting with `application/json`:

```php
$response = $client->post('/events', [
    'name' => 'signup',
], [
    'headers' => [
        'Content-Type' => 'application/json',
    ],
]);

$payload = $response->getJson();
```

### Sending prepared requests

Choose the send path according to which client behavior you need:

| Method | Redirects | Cookie jar | Mocks | Client options |
| --- | --- | --- | --- | --- |
| `send($request, $options = [])` | yes | yes | yes | yes |
| `sendRequest($request)` | no | no | no | no |

`sendRequest()` is the strict PSR-18 path and delegates directly to the configured handler. The default cURL handler requires an absolute HTTP or HTTPS URL with a host. The verb methods can resolve a relative URL against `baseUrl` before sending it.

You can seed the client's cookie jar with `addCookie($cookie)`. Use `getHandler()` when you need direct access to the configured transport.

## Configuration

`Client` accepts an options array at construction time. Request methods and `send()` accept per-request options that override these defaults.

| Option | Default | Purpose |
| --- | --- | --- |
| `handler` | `CurlHandler::class` | handler instance or class name used for transport |
| `baseUrl` | `null` | base URL for resolving relative request URLs |
| `headers` | `[]` | default request headers |
| `auth` | `['type' => 'basic', 'username' => null, 'password' => null]` | authentication settings |
| `proxy` | `['username' => null, 'password' => null]` | credentials used for `Proxy-Authorization` |
| `protocolVersion` | `1.1` | request protocol version (`1.0`, `1.1`, or `2.0`) |
| `timeout` | `30` | timeout in seconds for handlers that support it |
| `maxRedirects` | `0` | maximum redirects followed by `send()` and the verb methods |
| `maxRedirectBodySize` | `16_777_216` | maximum bytes buffered to replay a non-seekable body |
| `sensitiveHeaders` | `[]` | additional headers removed from cross-origin redirects |

Example:

```php
$client = new Client([
    'baseUrl' => 'https://api.example.com',
    'timeout' => 5,
    'maxRedirects' => 3,
    'auth' => [
        'type' => 'basic',
        'username' => 'api-user',
        'password' => 'api-pass',
    ],
]);
```

## Redirects and cookies

`Client::send()` and the verb methods can follow redirects when `maxRedirects` is greater than `0`.

### Redirect behavior

Relative `Location` values are resolved against the current request URI. Empty, malformed, non-HTTP(S), credential-bearing, and hostless redirect locations throw a `RequestException`. Redirect loops also throw a `RequestException`.

Fragment-only locations retain the current query. Locations with a new path or an explicit query do not inherit the old query. Fragments are removed before sending the redirected request.

Redirect methods and bodies follow these rules:

- `303` changes every method except `HEAD` to `GET` and removes the request body
- `301` and `302` change `POST` to `GET` and remove the request body
- `307` and `308` preserve the method and body
- other preserved-method redirects rewind the request body before replaying it

When redirects are enabled, a non-seekable request body is copied to a seekable stream before the first request. If it cannot be read or exceeds `maxRedirectBodySize`, the request throws a `RequestException`.

Cross-origin redirects remove `Authorization`, `Proxy-Authorization`, and `Referer` headers, plus any names configured in `sensitiveHeaders`. Client `auth` and `ssl` options are not forwarded to the new origin.

### Cookie behavior

The client also keeps an in-memory cookie jar:

- cookies received from responses are stored on the `Client` instance
- cookies are matched by host-only or domain scope, path, expiry, and the `Secure` flag
- matching cookies are sent on later requests from that same client instance, with longer matching paths first
- cookies are not persisted beyond the life of the PHP process

Malformed cookies, invalid domains, `Secure` cookies received over HTTP, invalid `__Secure-` / `__Host-` cookies, and insecure cookies that would overwrite an overlapping secure cookie are ignored. The jar also limits individual cookie size, cookies per domain, total cookies, and the generated `Cookie` header size.

## Working with responses

`Client\Response` gives you the usual response information plus a few convenience helpers for client-side tasks.

### Check status and read headers

Use the status helper that matches the range you accept:

| Method | Matches |
| --- | --- |
| `isSuccess()` | `200` through `299` |
| `isOk()` | `200` through `399` |
| `isRedirect()` | `301`, `302`, `303`, `307`, or `308` |

You can also read the status and headers directly:

```php
$response = $client->get('https://api.example.com/status');

if (!$response->isOk()) {
    return;
}

$contentType = $response->getHeaderLine('Content-Type');
```

### Decode JSON responses

Use `getJson()` when the response body is JSON:

```php
$data = $response->getJson();
```

Important behavior:

- Valid JSON scalars are returned as their decoded PHP values (for example `true`, `123`, or `'ok'`), and a JSON `null` literal returns `null`.
- Invalid JSON throws a `RuntimeException`.

### Read response cookies

If the response includes `Set-Cookie` headers, `Response` can parse them into `Cookie` objects:

```php
$cookies = $response->getCookies();
$session = $response->getCookie('session');
```

## Handlers

The HTTP client delegates network I/O to a handler. Configure `handler` with an instance or a class name. Class names are instantiated directly; provide an instance when a custom handler needs constructor dependencies.

```php
use Fyre\Http\Client;
use Fyre\Http\Client\Handlers\CurlHandler;

$client = new Client([
    'handler' => CurlHandler::class,
]);
```

### cURL handler

Implemented by `CurlHandler` (the default handler). It uses PHP’s cURL extension and supports a few handler-specific options:

- `timeout` (`int`): request timeout in seconds
- `ssl` (`array`): SSL options (`cert`, `password`, `key`)
- `verifyPeer` (`bool`): whether to verify the peer certificate

The handler automatically decompresses supported response encodings and removes `Content-Encoding` and `Content-Length` from decoded responses. Those headers are preserved for `HEAD` requests and responses with status codes below `200`, `204`, or `304`, where no body is decoded.

### Mock handler

Implemented by `MockHandler`. It returns pre-configured responses and performs no network I/O.

Mock matching supports:

- method + URL matching
- `*` wildcards in the mock URL
- an optional match callback to decide whether a mock applies to a request

### Custom handlers

To build a custom transport, extend `ClientHandler` and implement `send(RequestInterface $request, array $options = []): Response`.

## Testing

For PHPUnit tests, prefer the `HttpClientTestTrait` helpers documented in [HTTP Client Testing](../testing/http-client.md).

If you need to register mocks manually, `Client` provides a static mock facility via `Client::addMockResponse()` and `Client::clearMockResponses()`. While mocks are active, `Client::send()` uses them instead of the configured handler.

Mocks are global to the `Client` class (static), so ensure they’re cleared between tests (the trait does this automatically).

## Behavior notes

- Digest authentication may make an initial `401` challenge request before retrying with credentials.
- Array body data uses JSON when `Content-Type` starts with `application/json`, `multipart/form-data` when files or streams are present, and `application/x-www-form-urlencoded` otherwise. The client sets the form content type when needed.
- Query parameters are merged recursively with any query already present in the URL.

## Related

- [HTTP Client Testing](../testing/http-client.md)
- [Cookies](cookies.md)
- [URI](uri.md)
- [HTTP Responses](responses.md)
