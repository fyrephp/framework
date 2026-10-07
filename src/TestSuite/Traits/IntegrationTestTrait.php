<?php
declare(strict_types=1);

namespace Fyre\TestSuite\Traits;

use Closure;
use Fyre\Event\Event;
use Fyre\Event\EventManager;
use Fyre\Http\ClientResponse;
use Fyre\Http\MiddlewareQueue;
use Fyre\Http\RequestHandler;
use Fyre\Http\ServerRequest;
use Fyre\Http\Uri;
use Fyre\Router\RouteHandler;
use Fyre\Security\CsrfProtection;
use Fyre\TestSuite\Constraint\Response\BodyContains;
use Fyre\TestSuite\Constraint\Response\BodyEmpty;
use Fyre\TestSuite\Constraint\Response\BodyEquals;
use Fyre\TestSuite\Constraint\Response\BodyNotContains;
use Fyre\TestSuite\Constraint\Response\BodyNotEmpty;
use Fyre\TestSuite\Constraint\Response\BodyNotEquals;
use Fyre\TestSuite\Constraint\Response\ContentType;
use Fyre\TestSuite\Constraint\Response\CookieEquals;
use Fyre\TestSuite\Constraint\Response\CookieNotSet;
use Fyre\TestSuite\Constraint\Response\CookieSet;
use Fyre\TestSuite\Constraint\Response\File;
use Fyre\TestSuite\Constraint\Response\HeaderContains;
use Fyre\TestSuite\Constraint\Response\HeaderEquals;
use Fyre\TestSuite\Constraint\Response\HeaderNotContains;
use Fyre\TestSuite\Constraint\Response\HeaderNotSet;
use Fyre\TestSuite\Constraint\Response\HeaderSet;
use Fyre\TestSuite\Constraint\Response\StatusCode;
use Fyre\TestSuite\Constraint\Response\StatusCodeBetween;
use Fyre\TestSuite\Constraint\Session\FlashMessageEquals;
use Fyre\TestSuite\Constraint\Session\SessionEquals;
use Fyre\TestSuite\Constraint\Session\SessionHasKey;
use Fyre\TestSuite\Constraint\Session\SessionNotHasKey;
use Fyre\TestSuite\TestCase;
use Fyre\Utility\Arr;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\After;
use RuntimeException;
use Throwable;

use function array_replace_recursive;
use function array_walk_recursive;
use function basename;
use function copy;
use function filesize;
use function http_build_query;
use function in_array;
use function is_file;
use function json_encode;
use function parse_str;
use function sprintf;
use function str_starts_with;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const UPLOAD_ERR_OK;

/**
 * Test case helpers for integration tests.
 *
 * @phpstan-require-extends TestCase
 */
trait IntegrationTestTrait
{
    protected array $cookies = [];

    /**
     * @var (Closure(Event, Throwable): void)|null
     */
    protected Closure|null $errorHandlerCallback = null;

    protected array $request = [];

    protected array $requestData = [];

    protected array $requestFiles = [];

    protected ClientResponse|null $response = null;

    protected array $session = [];

    protected array $temporaryUploads = [];

    /**
     * Assert that the content type of the response matches the expected type.
     *
     * @param string $type The expected content type.
     * @param string $message The message to display on failure.
     */
    public function assertContentType(string $type, string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new ContentType($type),
            $message
        );
    }

    /**
     * Assert that the response contains a cookie with the expected value.
     *
     * @param string $value The expected cookie value.
     * @param string $name The cookie name.
     * @param string $message The message to display on failure.
     */
    public function assertCookie(string $value, string $name, string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new CookieEquals($value, $name),
            $message
        );
    }

    /**
     * Assert that a cookie is set in the response.
     *
     * @param string $name The cookie name.
     * @param string $message The message to display on failure.
     */
    public function assertCookieIsSet(string $name, string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new CookieSet($name),
            $message
        );
    }

    /**
     * Assert that a cookie is not set in the response.
     *
     * @param string $name The cookie name.
     * @param string $message The message to display on failure.
     */
    public function assertCookieNotSet(string $name, string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new CookieNotSet($name),
            $message
        );
    }

    /**
     * Assert that the response is a file download.
     *
     * @param string $path The expected file path.
     * @param string $message The message to display on failure.
     */
    public function assertFileResponse(string $path, string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new File($path),
            $message
        );
    }

    /**
     * Assert that a flash message is set in the session.
     *
     * @param mixed $value The expected flash message value.
     * @param string $key The flash message key.
     * @param string $message The message to display on failure.
     */
    public function assertFlashMessage(mixed $value, string $key, string $message = ''): void
    {
        $this->assertThat(
            $_SESSION,
            new FlashMessageEquals($value, $key),
            $message
        );
    }

    /**
     * Assert that a header in the response matches the expected value.
     *
     * @param string $value The expected header value.
     * @param string $header The header name.
     * @param string $message The message to display on failure.
     */
    public function assertHeader(string $value, string $header, string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new HeaderEquals($value, $header),
            $message
        );
    }

    /**
     * Assert that a header in the response contains a value.
     *
     * @param string $value The expected header value.
     * @param string $header The header name.
     * @param string $message The message to display on failure.
     */
    public function assertHeaderContains(string $value, string $header, string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new HeaderContains($value, $header),
            $message
        );
    }

    /**
     * Assert that a header in the response does not contain a value.
     *
     * @param string $value The expected header value.
     * @param string $header The header name.
     * @param string $message The message to display on failure.
     */
    public function assertHeaderNotContains(string $value, string $header, string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new HeaderNotContains($value, $header),
            $message
        );
    }

    /**
     * Assert that the response is not a redirect.
     *
     * @param string $message The message to display on failure.
     */
    public function assertNoRedirect(string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new HeaderNotSet('Location'),
            $message
        );
    }

    /**
     * Assert that the response is a redirect.
     *
     * @param string $message The message to display on failure.
     */
    public function assertRedirect(string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new HeaderSet('Location'),
            $message
        );
    }

    /**
     * Assert that the response redirect contains a specific URL.
     *
     * @param string $url The URL to check for.
     * @param string $message The message to display on failure.
     */
    public function assertRedirectContains(string $url, string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new HeaderContains($url, 'Location'),
            $message
        );
    }

    /**
     * Assert that the response redirect equals a specific URL.
     *
     * @param string $url The URL to check for.
     * @param string $message The message to display on failure.
     */
    public function assertRedirectEquals(string $url, string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new HeaderEquals($url, 'Location'),
            $message
        );
    }

    /**
     * Assert that the response redirect does not contain a specific URL.
     *
     * @param string $url The URL to check for.
     * @param string $message The message to display on failure.
     */
    public function assertRedirectNotContains(string $url, string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new HeaderNotContains($url, 'Location'),
            $message
        );
    }

    /**
     * Assert that the response status code matches the expected code.
     *
     * @param int $code The expected status code.
     * @param string $message The message to display on failure.
     */
    public function assertResponseCode(int $code, string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new StatusCode($code),
            $message
        );
    }

    /**
     * Assert that the response body contains a specific string.
     *
     * @param string $needle The expected response body.
     * @param string $message The message to display on failure.
     */
    public function assertResponseContains(string $needle, string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new BodyContains($needle),
            $message
        );
    }

    /**
     * Assert that the response body is empty.
     *
     * @param string $message The message to display on failure.
     */
    public function assertResponseEmpty(string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new BodyEmpty(),
            $message
        );
    }

    /**
     * Assert that the response body equals the expected contents.
     *
     * @param string $body The expected response body.
     * @param string $message The message to display on failure.
     */
    public function assertResponseEquals(string $body, string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new BodyEquals($body),
            $message
        );
    }

    /**
     * Assert that the response is an error (status code 400-599).
     *
     * @param string $message The message to display on failure.
     */
    public function assertResponseError(string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new StatusCodeBetween(400, 599),
            $message
        );
    }

    /**
     * Assert that the response is a failure (status code 500-599).
     *
     * @param string $message The message to display on failure.
     */
    public function assertResponseFailure(string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new StatusCodeBetween(500, 599),
            $message
        );
    }

    /**
     * Assert that the response body does not contain a specific string.
     *
     * @param string $needle The string to search for.
     * @param string $message The message to display on failure.
     */
    public function assertResponseNotContains(string $needle, string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new BodyNotContains($needle),
            $message
        );
    }

    /**
     * Assert that the response body is not empty.
     *
     * @param string $message The message to display on failure.
     */
    public function assertResponseNotEmpty(string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new BodyNotEmpty(),
            $message
        );
    }

    /**
     * Assert that the response body does not equal the expected contents.
     *
     * @param string $body The expected response body.
     * @param string $message The message to display on failure.
     */
    public function assertResponseNotEquals(string $body, string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new BodyNotEquals($body),
            $message
        );
    }

    /**
     * Assert that the response is OK (status code 200-204).
     *
     * @param string $message The message to display on failure.
     */
    public function assertResponseOk(string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new StatusCodeBetween(200, 204),
            $message
        );
    }

    /**
     * Assert that the response is successful.
     *
     * @param string $message The message to display on failure.
     */
    public function assertResponseSuccess(string $message = ''): void
    {
        if (!$this->response) {
            $this->fail('No response has been set.');
        }

        $this->assertThat(
            $this->response,
            new StatusCodeBetween(200, 308),
            $message
        );
    }

    /**
     * Assert that a session key has the expected value.
     *
     * @param mixed $value The expected session value.
     * @param string $path The session key path.
     * @param string $message The message to display on failure.
     */
    public function assertSession(mixed $value, string $path, string $message = ''): void
    {
        $this->assertThat(
            $_SESSION,
            new SessionEquals($value, $path),
            $message
        );
    }

    /**
     * Assert that a session key exists.
     *
     * @param string $path The session key path.
     * @param string $message The message to display on failure.
     */
    public function assertSessionHasKey(string $path, string $message = ''): void
    {
        $this->assertThat(
            $_SESSION,
            new SessionHasKey($path),
            $message
        );
    }

    /**
     * Assert that a session key does not exist.
     *
     * @param string $path The session key path.
     * @param string $message The message to display on failure.
     */
    public function assertSessionNotHasKey(string $path, string $message = ''): void
    {
        $this->assertThat(
            $_SESSION,
            new SessionNotHasKey($path),
            $message
        );
    }

    /**
     * Set a cookie for the request.
     *
     * @param string $name The cookie name.
     * @param string $value The cookie value.
     */
    public function cookie(string $name, string $value): void
    {
        $this->cookies[$name] = $value;
    }

    /**
     * Add data to the next request.
     *
     * @param array<string, mixed> $data The request data.
     */
    public function data(array $data): void
    {
        $this->requestData = array_replace_recursive(
            $this->requestData,
            $data
        );
    }

    /**
     * Send a DELETE request to the application.
     *
     * @param string $path The request path.
     */
    public function delete(string $path): void
    {
        $this->sendRequest($path, 'DELETE');
    }

    /**
     * Lets exceptions propagate through the HTTP test request.
     */
    public function disableErrorRendering(): void
    {
        if ($this->errorHandlerCallback !== null) {
            return;
        }

        $this->errorHandlerCallback = static function(Event $event, Throwable $exception): void {
            throw $exception;
        };

        $this->app->use(EventManager::class)->on('Error.beforeRender', $this->errorHandlerCallback, EventManager::PRIORITY_HIGH);
    }

    /**
     * Enable CSRF token for the request.
     *
     * @param string $cookieName The name of the CSRF token cookie.
     */
    public function enableCsrfToken(string $cookieName = 'CsrfToken'): void
    {
        $csrfProtection = $this->app->use(CsrfProtection::class);
        $field = $csrfProtection->getField();
        $header = $csrfProtection->getHeader();

        if ($field === null && $header === null) {
            throw new LogicException('CSRF token field and header are disabled.');
        }

        $formToken = $csrfProtection->getFormToken();

        if ($formToken === null) {
            throw new LogicException('Failed to generate CSRF form token.');
        }

        $this->cookies[$cookieName] = $csrfProtection->getCookieToken();

        if ($field !== null) {
            $this->data([$field => $formToken]);
        }

        if ($header !== null) {
            $this->request['headers'] ??= [];
            $this->request['headers'][$header] = $formToken;
        }
    }

    /**
     * Enables error response rendering for HTTP test requests.
     */
    public function enableErrorRendering(): void
    {
        if ($this->errorHandlerCallback === null) {
            return;
        }

        $this->app->use(EventManager::class)->off('Error.beforeRender', $this->errorHandlerCallback);
        $this->errorHandlerCallback = null;
    }

    /**
     * Add an uploaded file to the request.
     *
     * @param string $name The file field name using "dot" notation.
     * @param string $path The file path.
     * @param string|null $clientFilename The client filename.
     * @param string|null $clientMediaType The client media type.
     */
    public function file(
        string $name,
        string $path,
        string|null $clientFilename = null,
        string|null $clientMediaType = null
    ): void {
        if (
            !is_file($path) ||
            ($size = filesize($path)) === false
        ) {
            throw new InvalidArgumentException(sprintf(
                'Uploaded file `%s` is not valid.',
                $path
            ));
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'fyre-upload-');

        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to create temporary uploaded file.');
        }

        if (!copy($path, $temporaryPath)) {
            @unlink($temporaryPath);

            throw new RuntimeException(sprintf(
                'Uploaded file `%s` could not be copied.',
                $path
            ));
        }

        $this->temporaryUploads[] = $temporaryPath;

        $this->requestFiles = Arr::setDot(
            $this->requestFiles,
            $name,
            [
                'tmp_name' => $temporaryPath,
                'name' => $clientFilename ?? basename($path),
                'type' => $clientMediaType,
                'size' => $size,
                'error' => UPLOAD_ERR_OK,
            ]
        );
    }

    /**
     * Send a GET request to the application.
     *
     * @param string $path The request path.
     */
    public function get(string $path): void
    {
        $this->sendRequest($path, 'GET');
    }

    /**
     * Send a HEAD request to the application.
     *
     * @param string $path The request path.
     */
    public function head(string $path): void
    {
        $this->sendRequest($path, 'HEAD');
    }

    /**
     * Send an OPTIONS request to the application.
     *
     * @param string $path The request path.
     */
    public function options(string $path): void
    {
        $this->sendRequest($path, 'OPTIONS');
    }

    /**
     * Send a PATCH request to the application.
     *
     * @param string $path The request path.
     * @param array<string, mixed> $data The request data.
     */
    public function patch(string $path, array $data = []): void
    {
        $this->sendRequest($path, 'PATCH', $data);
    }

    /**
     * Send a POST request to the application.
     *
     * @param string $path The request path.
     * @param array<string, mixed> $data The request data.
     */
    public function post(string $path, array $data = []): void
    {
        $this->sendRequest($path, 'POST', $data);
    }

    /**
     * Send a PUT request to the application.
     *
     * @param string $path The request path.
     * @param array<string, mixed> $data The request data.
     */
    public function put(string $path, array $data = []): void
    {
        $this->sendRequest($path, 'PUT', $data);
    }

    /**
     * Set the request as JSON.
     */
    public function requestAsJson(): void
    {
        $this->request['headers'] ??= [];
        $this->request['headers']['Accept'] = 'application/json';
        $this->request['headers']['Content-Type'] = 'application/json';
    }

    /**
     * Set session data.
     *
     * @param array<string, mixed> $data The session data.
     */
    public function session(array $data): void
    {
        $this->session = array_replace_recursive($this->session, $data);
    }

    /**
     * Cleanup after each test.
     */
    #[After]
    protected function cleanup(): void
    {
        foreach ($this->temporaryUploads as $path) {
            @unlink($path);
        }

        $this->cookies = [];
        $this->enableErrorRendering();
        $this->request = [];
        $this->requestData = [];
        $this->requestFiles = [];
        $this->response = null;
        $this->session = [];
        $this->temporaryUploads = [];
        $_SESSION = [];
    }

    /**
     * Send a request to the application.
     *
     * @param string $path The request path.
     * @param string $method The request method.
     * @param array<string, mixed> $data The request data.
     */
    protected function sendRequest(string $path, string $method, array $data = []): void
    {
        $requestData = $this->requestData;
        $requestFiles = $this->requestFiles;

        $this->requestData = [];
        $this->requestFiles = [];

        $uri = Uri::createFromString($path);

        $options = array_replace_recursive($this->request, [
            'headers' => [],
            'cookies' => $this->cookies,
            'get' => $uri->getQueryParams(),
            'data' => $requestData,
            'files' => $requestFiles,
            'server' => [
                'REQUEST_METHOD' => $method,
                'REQUEST_URI' => $uri->getPath(),
                'QUERY_STRING' => $uri->getQuery(),
            ],
        ]);

        if (in_array($method, ['DELETE', 'PATCH', 'POST', 'PUT'], true)) {
            $data = array_replace_recursive($data, $options['data']);
            $contentType = $options['headers']['Content-Type'] ?? null;

            if (
                $contentType === null &&
                ($data !== [] || $options['files'] !== [])
            ) {
                $contentType = $options['files'] === [] ?
                    'application/x-www-form-urlencoded' :
                    'multipart/form-data';

                $options['headers']['Content-Type'] = $contentType;
            }

            if (str_starts_with($contentType ?? '', 'application/json')) {
                $options['body'] = (string) json_encode($data, JSON_THROW_ON_ERROR);
                unset($options['data']);
            } else if (str_starts_with($contentType ?? '', 'application/x-www-form-urlencoded')) {
                $options['body'] = http_build_query($data);

                if ($method === 'POST') {
                    parse_str($options['body'], $options['data']);
                } else {
                    unset($options['data']);
                }
            } else {
                array_walk_recursive($data, static function(mixed &$value): void {
                    $value = (string) $value;
                });

                $options['data'] = $data;
            }
        }

        $this->app->use(MiddlewareQueue::class)->rewind();

        $routeHandler = $this->app->build(RouteHandler::class);
        $handler = $this->app->build(RequestHandler::class, [
            'fallbackHandler' => $routeHandler,
        ]);
        $request = $this->app->use(ServerRequest::class, ['options' => $options]);

        $_SESSION = $this->session;
        $this->response = null;

        try {
            $this->response = $handler->handle($request);
        } finally {
            $this->session = $_SESSION;
        }
    }
}
