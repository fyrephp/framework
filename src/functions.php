<?php
declare(strict_types=1);

use Fyre\Auth\Auth;
use Fyre\Cache\CacheManager;
use Fyre\Cache\Cacher;
use Fyre\Core\Config;
use Fyre\Core\Engine;
use Fyre\Core\Lang;
use Fyre\DB\Connection;
use Fyre\DB\ConnectionManager;
use Fyre\DB\Type;
use Fyre\DB\TypeParser;
use Fyre\Http\ClientResponse;
use Fyre\Http\Exceptions\BadRequestException;
use Fyre\Http\Exceptions\ConflictException;
use Fyre\Http\Exceptions\ForbiddenException;
use Fyre\Http\Exceptions\GoneException;
use Fyre\Http\Exceptions\HttpException;
use Fyre\Http\Exceptions\InternalServerException;
use Fyre\Http\Exceptions\MethodNotAllowedException;
use Fyre\Http\Exceptions\NotAcceptableException;
use Fyre\Http\Exceptions\NotFoundException;
use Fyre\Http\Exceptions\NotImplementedException;
use Fyre\Http\Exceptions\ServiceUnavailableException;
use Fyre\Http\Exceptions\UnauthorizedException;
use Fyre\Http\RedirectResponse;
use Fyre\Http\ServerRequest;
use Fyre\Http\Session\Session;
use Fyre\Http\Uri;
use Fyre\Log\LogManager;
use Fyre\Mail\Email;
use Fyre\Mail\MailManager;
use Fyre\ORM\Entity;
use Fyre\ORM\Model;
use Fyre\ORM\ModelRegistry;
use Fyre\Queue\QueueManager;
use Fyre\Router\Exceptions\RouterException;
use Fyre\Router\Router;
use Fyre\Security\Encryption\Encrypter;
use Fyre\Security\Encryption\EncryptionManager;
use Fyre\Utility\Collection;
use Fyre\Utility\DateTime\DateTime;
use Fyre\Utility\HtmlHelper;
use Fyre\View\View;

if (!function_exists('__')) {
    /**
     * Returns a language value.
     *
     * @param string $key The language key.
     * @param array<string, mixed> $data The data to insert.
     * @return array<string, mixed>|string|null The formatted language string.
     */
    function __(string $key, array $data = []): array|string|null
    {
        return app(Lang::class)->get($key, $data);
    }
}

if (!function_exists('abort')) {
    /**
     * Throws an HTTP exception for a status code.
     *
     * Note: Unsupported status codes fall back to an InternalServerException using the provided code.
     *
     * @param int $code The status code.
     * @param string $message The error message.
     *
     * @throws HttpException If the HTTP error is raised.
     */
    function abort(int $code = 500, string|null $message = null): never
    {
        throw match ($code) {
            400 => new BadRequestException($message),
            401 => new UnauthorizedException($message),
            403 => new ForbiddenException($message),
            404 => new NotFoundException($message),
            405 => new MethodNotAllowedException($message),
            406 => new NotAcceptableException($message),
            409 => new ConflictException($message),
            410 => new GoneException($message),
            501 => new NotImplementedException($message),
            503 => new ServiceUnavailableException($message),
            default => new InternalServerException($message, $code)
        };
    }
}

if (!function_exists('app')) {
    /**
     * Returns the shared Engine instance or resolves an instance from the container.
     *
     * @param string|null $alias The class alias.
     * @param array<string, mixed> $arguments The constructor arguments.
     * @return mixed The Engine or instance.
     */
    function app(string|null $alias = null, array $arguments = []): mixed
    {
        $app = Engine::getInstance();

        if ($alias === null) {
            return $app;
        }

        return $app->use($alias, $arguments);
    }
}

if (!function_exists('asset')) {
    /**
     * Generates a URL for an asset path.
     *
     * @param string $path The asset path.
     * @param bool $full Whether to use a full URL.
     * @return string The URL.
     *
     * Note: When $full is true, the URL is resolved relative to `App.baseUri`. Otherwise, the value is treated as-is.
     */
    function asset(string $path, bool $full = false): string
    {
        if ($full) {
            return (config('App.baseUri') |> Uri::createFromString(...))
                ->resolveRelativeUri($path)
                ->getUri();
        }

        return Uri::createFromString($path)->getUri();
    }
}

if (!function_exists('auth')) {
    /**
     * Loads a shared Auth instance.
     *
     * @return Auth The Auth.
     */
    function auth(): Auth
    {
        return app(Auth::class);
    }
}

if (!function_exists('authorize')) {
    /**
     * Authorizes an access rule.
     *
     * @param string $rule The access rule name.
     * @param mixed ...$args Additional arguments for the access rule.
     */
    function authorize(string $rule, mixed ...$args): void
    {
        auth()
            ->access()
            ->authorize($rule, ...$args);
    }
}

if (!function_exists('cache')) {
    /**
     * Loads a shared cache instance.
     *
     * @param string $key The config key.
     * @return Cacher The cache handler.
     */
    function cache(string $key = CacheManager::DEFAULT): Cacher
    {
        return app(CacheManager::class)->use($key);
    }
}

if (!function_exists('can')) {
    /**
     * Checks whether an access rule is allowed.
     *
     * @param string $rule The access rule name.
     * @param mixed ...$args Additional arguments for the access rule.
     * @return bool Whether the access rule was allowed.
     */
    function can(string $rule, mixed ...$args): bool
    {
        return auth()
            ->access()
            ->allows($rule, ...$args);
    }
}

if (!function_exists('can_any')) {
    /**
     * Checks whether any access rule is allowed.
     *
     * @param string[] $rules The access rule names.
     * @param mixed ...$args Additional arguments for the access rule.
     * @return bool Whether any access rule was allowed.
     */
    function can_any(array $rules, mixed ...$args): bool
    {
        return auth()
            ->access()
            ->any($rules, ...$args);
    }
}

if (!function_exists('can_none')) {
    /**
     * Checks whether no access rule is allowed.
     *
     * @param string[] $rules The access rule names.
     * @param mixed ...$args Additional arguments for the access rule.
     * @return bool Whether no access rule was allowed.
     */
    function can_none(array $rules, mixed ...$args): bool
    {
        return auth()
            ->access()
            ->none($rules, ...$args);
    }
}

if (!function_exists('cannot')) {
    /**
     * Checks whether an access rule is not allowed.
     *
     * @param string $rule The access rule name.
     * @param mixed ...$args Additional arguments for the access rule.
     * @return bool Whether the access rule was not allowed.
     */
    function cannot(string $rule, mixed ...$args): bool
    {
        return auth()
            ->access()
            ->denies($rule, ...$args);
    }
}

if (!function_exists('collect')) {
    /**
     * Creates a new Collection.
     *
     * @param array<mixed>|Closure|JsonSerializable|Traversable<mixed>|null $source The source.
     * @return Collection<array-key, mixed> The new Collection instance.
     */
    function collect(array|Closure|JsonSerializable|Traversable|null $source): Collection
    {
        return new Collection($source);
    }
}

if (!function_exists('config')) {
    /**
     * Retrieves a value from the config using "dot" notation.
     *
     * @param string|null $key The config key.
     * @param mixed $default The default value.
     * @return mixed The Config instance or the config value.
     */
    function config(string|null $key = null, mixed $default = null): mixed
    {
        $config = app()->use(Config::class);

        if ($key === null) {
            return $config;
        }

        return $config->get($key, $default);
    }
}

if (!function_exists('db')) {
    /**
     * Loads a shared database connection.
     *
     * @param string $key The config key.
     * @return Connection The connection.
     */
    function db(string $key = ConnectionManager::DEFAULT): Connection
    {
        return app(ConnectionManager::class)->use($key);
    }
}

if (!function_exists('dd')) {
    /**
     * Dumps data and dies.
     *
     * @param mixed ...$data The data to dump.
     */
    function dd(mixed ...$data): never
    {
        dump(...$data);
        exit();
    }
}

if (!function_exists('dump')) {
    /**
     * Dumps data.
     *
     * Note: Uses `var_dump()` and wraps output in `<pre>` tags when not running in CLI.
     *
     * @param mixed ...$data The data to dump.
     */
    function dump(mixed ...$data): void
    {
        foreach ($data as $item) {
            if (PHP_SAPI !== 'cli') {
                echo '<pre>';
            }

            var_dump($item);

            if (PHP_SAPI !== 'cli') {
                echo '</pre>';
            }
        }
    }
}

if (!function_exists('element')) {
    /**
     * Renders an element.
     *
     * @param string $file The element file.
     * @param array<string, mixed> $data The view data.
     * @return string The rendered element.
     */
    function element(string $file, array $data = []): string
    {
        return app(View::class)
            ->element($file, $data);
    }
}

if (!function_exists('email')) {
    /**
     * Creates a new Email instance for the configured mailer.
     *
     * @param string $key The config key.
     * @return Email The new Email instance.
     */
    function email(string $key = MailManager::DEFAULT): Email
    {
        return app(MailManager::class)->use($key)->email();
    }
}

if (!function_exists('encryption')) {
    /**
     * Loads a shared encryption instance.
     *
     * @param string $key The config key.
     * @return Encrypter The encryption handler.
     */
    function encryption(string $key = EncryptionManager::DEFAULT): Encrypter
    {
        return app(EncryptionManager::class)->use($key);
    }
}

if (!function_exists('env')) {
    /**
     * Retrieves an environment variable.
     *
     * @param string $name The variable name.
     * @param mixed $default The default value.
     * @return mixed The variable value.
     *
     * Note: An empty string is treated as not set and will return the default value.
     */
    function env(string $name, mixed $default = null): mixed
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            return $default;
        }

        return $value;
    }
}

if (!function_exists('escape')) {
    /**
     * Escapes characters in a string for use in HTML.
     *
     * @param string $string The input string.
     * @return string The escaped string.
     */
    function escape(string $string): string
    {
        return app(HtmlHelper::class)->escape($string);
    }
}

if (!function_exists('json')) {
    /**
     * Creates a new ClientResponse with JSON data.
     *
     * @param mixed $data The data to send.
     * @param bool $stream Whether to stream an iterable as a JSON array.
     * @return ClientResponse The new ClientResponse instance.
     */
    function json(mixed $data, bool $stream = false): ClientResponse
    {
        return response()->withJson($data, $stream);
    }
}

if (!function_exists('log_message')) {
    /**
     * Logs a message.
     *
     * @param string $type The log type.
     * @param string $message The log message.
     * @param array<string, mixed> $data Additional data to interpolate.
     */
    function log_message(string $type, string $message, array $data = []): void
    {
        app(LogManager::class)->handle($type, $message, $data);
    }
}

if (!function_exists('logged_in')) {
    /**
     * Checks whether the current user is logged in.
     *
     * @return bool Whether the current user is logged in.
     */
    function logged_in(): bool
    {
        return auth()->isLoggedIn();
    }
}

if (!function_exists('model')) {
    /**
     * Loads a shared Model instance.
     *
     * @param string $alias The model alias.
     * @return Model The Model.
     */
    function model(string $alias): Model
    {
        return app(ModelRegistry::class)->use($alias);
    }
}

if (!function_exists('now')) {
    /**
     * Creates a new DateTime set to now.
     *
     * @return DateTime The DateTime.
     */
    function now(): DateTime
    {
        return DateTime::now();
    }
}

if (!function_exists('queue')) {
    /**
     * Pushes a job to the queue.
     *
     * @param class-string $className The job class.
     * @param array<string, mixed> $arguments The job arguments.
     * @param array<string, mixed> $options The job options.
     */
    function queue(string $className, array $arguments = [], array $options = []): void
    {
        app(QueueManager::class)->push($className, $arguments, $options);
    }
}

if (!function_exists('redirect')) {
    /**
     * Creates a new RedirectResponse.
     *
     * @param string|Uri $uri The URI.
     * @param int $code The status code.
     * @param array<string, mixed> $options The options.
     * @return RedirectResponse The RedirectResponse.
     */
    function redirect(string|Uri $uri, int $code = 302, array $options = []): RedirectResponse
    {
        return app(RedirectResponse::class, [
            'uri' => $uri,
            'code' => $code,
            'options' => $options,
        ]);
    }
}

if (!function_exists('request')) {
    /**
     * Loads a shared ServerRequest instance.
     *
     * @param string|null $key The key.
     * @param string|null $as The type.
     * @return mixed The ServerRequest (no args) or a request data value.
     */
    function request(string|null $key = null, string|null $as = null): mixed
    {
        $request = app(ServerRequest::class);

        if (func_num_args() === 0) {
            return $request;
        }

        return $request->getData($key, $as);
    }
}

if (!function_exists('response')) {
    /**
     * Creates a ClientResponse instance.
     *
     * @return ClientResponse The ClientResponse.
     */
    function response(): ClientResponse
    {
        return app(ClientResponse::class);
    }
}

if (!function_exists('route')) {
    /**
     * Generates a URL for a named route.
     *
     * @param string $name The name.
     * @param array<string, mixed> $arguments The route arguments.
     * @param string|null $scheme The route scheme.
     * @param string|null $host The route host.
     * @param int|null $port The route port.
     * @param bool|null $full Whether to use a full URL.
     * @return string The URL.
     *
     * @throws RouterException If the route alias does not exist, required arguments are missing, or a parameter value is invalid.
     */
    function route(string $name, array $arguments = [], string|null $scheme = null, string|null $host = null, int|null $port = null, bool|null $full = null): string
    {
        return app(Router::class)
            ->url(
                $name,
                $arguments,
                $scheme,
                $host,
                $port,
                $full
            );
    }
}

if (!function_exists('session')) {
    /**
     * Gets or sets a session value.
     *
     * @param string|null $key The session key.
     * @param mixed $value The session value.
     * @return mixed The Session instance or the session value.
     *
     * Note: When $key is null, the Session instance is returned. When only $key is provided, it is read from the
     * session; otherwise the value is set.
     */
    function session(string|null $key = null, mixed $value = null): mixed
    {
        $session = app(Session::class);

        if ($key === null) {
            return $session;
        }

        if (func_num_args() === 1) {
            return $session->get($key);
        }

        return $session->set($key, $value);
    }
}

if (!function_exists('type')) {
    /**
     * Returns the TypeParser or resolves a Type by name.
     *
     * @param string|null $type The value type.
     * @return Type|TypeParser The TypeParser or Type.
     */
    function type(string|null $type = null): Type|TypeParser
    {
        $typeParser = app(TypeParser::class);

        if ($type === null) {
            return $typeParser;
        }

        return $typeParser->use($type);
    }
}

if (!function_exists('user')) {
    /**
     * Returns the authenticated user.
     *
     * @return Entity|null The current user.
     */
    function user(): Entity|null
    {
        return auth()->user();
    }
}

if (!function_exists('view')) {
    /**
     * Renders a view template.
     *
     * @param string $template The template file.
     * @param array<string, mixed> $data The view data.
     * @param string|null $layout The layout.
     * @return string The rendered template.
     *
     * Note: Uses `App.defaultLayout` when no layout is provided.
     */
    function view(string $template, array $data = [], string|null $layout = null): string
    {
        return app(View::class)
            ->setData($data)
            ->setLayout($layout ?? config('App.defaultLayout'))
            ->render($template);
    }
}
