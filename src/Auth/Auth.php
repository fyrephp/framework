<?php
declare(strict_types=1);

namespace Fyre\Auth;

use Fyre\Core\Config;
use Fyre\Core\Container;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Core\Traits\MacroTrait;
use Fyre\ORM\Entity;
use Fyre\Router\Router;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

use function array_filter;
use function is_numeric;
use function is_string;
use function is_subclass_of;
use function sprintf;

/**
 * Coordinates authenticators and exposes the current authenticated user.
 *
 * Note: Authenticators are loaded from configuration and executed in order until one
 * returns an authenticated user. Stateful identities are persisted by all configured
 * authenticators, while stateless identities apply only to the current request.
 */
class Auth
{
    use DebugTrait;
    use MacroTrait;

    protected Access $access;

    /**
     * @var array<string, Authenticator>
     */
    protected array $authenticators = [];

    protected Identifier $identifier;

    protected string $loginRoute;

    protected Authenticator|null $successfulAuthenticator = null;

    protected Entity|null $user = null;

    /**
     * Constructs an Auth.
     *
     * @param Container $container The Container.
     * @param Router $router The Router.
     * @param Config $config The Config.
     *
     * @throws InvalidArgumentException If an authenticator is not valid.
     */
    public function __construct(
        protected Container $container,
        protected Router $router,
        Config $config
    ) {
        $this->loginRoute = $config->get('Auth.loginRoute', 'login');

        $authenticators = $config->get('Auth.authenticators', []);

        foreach ($authenticators as $key => $options) {
            if (
                !isset($options['className']) ||
                !is_string($options['className']) ||
                !is_subclass_of($options['className'], Authenticator::class)
            ) {
                throw new InvalidArgumentException(sprintf(
                    'Authenticator `%s` must extend `%s`.',
                    $options['className'] ?? '',
                    Authenticator::class
                ));
            }

            if (is_numeric($key)) {
                $key = null;
            }

            /** @var class-string<Authenticator> $className */
            $className = $options['className'];

            $authenticator = $container->build($className, [
                'auth' => $this,
                'options' => $options,
            ]);

            $this->addAuthenticator($authenticator, $key);
        }
    }

    /**
     * Returns the Access.
     *
     * @return Access The Access instance.
     */
    public function access(): Access
    {
        return $this->access ??= $this->container->build(Access::class, [
            'userResolver' => fn(): Entity|null => $this->user(),
        ]);
    }

    /**
     * Adds an Authenticator.
     *
     * @param Authenticator $authenticator The Authenticator.
     * @param string|null $key The key, or null to use the authenticator class name.
     * @return static The Auth instance.
     */
    public function addAuthenticator(Authenticator $authenticator, string|null $key = null)
    {
        $key ??= $authenticator::class;

        $this->authenticators[$key] = $authenticator;

        return $this;
    }

    /**
     * Attempts to log in a user.
     *
     * @param string $identifier The user identifier.
     * @param string $password The user password.
     * @param bool $rememberMe Whether to remember the user (forwarded to authenticators).
     * @return Entity|null The Entity instance for the authenticated user or null if authentication fails.
     */
    public function attempt(string $identifier, string $password, bool $rememberMe = false): Entity|null
    {
        $user = $this->identifier()->attempt($identifier, $password);

        if (!$user) {
            return null;
        }

        $this->login($user, $rememberMe);

        return $user;
    }

    /**
     * Attempts to authenticate a request using the configured authenticators.
     *
     * @param ServerRequestInterface $request The ServerRequest.
     * @return Entity|null The Entity instance for the authenticated user or null if authentication fails.
     */
    public function authenticate(ServerRequestInterface $request): Entity|null
    {
        $this->successfulAuthenticator = null;

        foreach ($this->authenticators as $authenticator) {
            $user = $authenticator->authenticate($request);

            if (!$user) {
                continue;
            }

            if ($authenticator instanceof StatelessAuthenticatorInterface) {
                $this->user = $user;
            } else {
                $this->login($user);
            }

            $this->successfulAuthenticator = $authenticator;

            return $user;
        }

        return null;
    }

    /**
     * Returns an Authenticator by key.
     *
     * @param string $key The key.
     * @return Authenticator|null The Authenticator instance for the key or null if it is not defined.
     */
    public function authenticator(string $key): Authenticator|null
    {
        return $this->authenticators[$key] ?? null;
    }

    /**
     * Returns the authenticators.
     *
     * @return array<string, Authenticator> The configured authenticators.
     */
    public function authenticators(): array
    {
        return $this->authenticators;
    }

    /**
     * Returns the login URL.
     *
     * Note: When a {@see UriInterface} is provided, only the path/query/fragment are kept
     * (host/scheme/port are stripped) before adding it to the `url` query parameter.
     *
     * @param string|UriInterface|null $redirect The redirect URI.
     * @return string The login URL.
     */
    public function getLoginUrl(string|UriInterface|null $redirect = null): string
    {
        if ($redirect instanceof UriInterface) {
            $redirect = (string) $redirect
                ->withPort(null)
                ->withScheme('')
                ->withHost('');
        }

        return $this->router->url($this->loginRoute, [
            '?' => array_filter([
                'url' => $redirect,
            ]),
        ]);
    }

    /**
     * Returns the Identifier.
     *
     * @return Identifier The Identifier instance.
     */
    public function identifier(): Identifier
    {
        return $this->identifier ??= $this->container->build(Identifier::class);
    }

    /**
     * Starts impersonating a user.
     *
     * Note: The impersonated identity takes effect on the next request.
     *
     * @param Entity $user The user to impersonate.
     * @return static The Auth instance.
     *
     * @throws LogicException If no user is logged in or the current identity does not support impersonation.
     */
    public function impersonate(Entity $user): static
    {
        if (!$this->user) {
            throw new LogicException('A user must be logged in before impersonating another user.');
        }

        $authenticator = $this->impersonationAuthenticator();

        if (!$authenticator) {
            throw new LogicException('Impersonation requires a compatible authenticator.');
        }

        $authenticator->impersonate($this->user, $user);

        return $this;
    }

    /**
     * Returns the original user while impersonating.
     *
     * @return Entity|null The original user, or null if impersonation is not active.
     */
    public function impersonator(): Entity|null
    {
        $authenticator = $this->impersonationAuthenticator();

        if (!$authenticator?->isImpersonating()) {
            return null;
        }

        return $authenticator->impersonator();
    }

    /**
     * Checks whether the current user is being impersonated.
     *
     * @return bool Whether impersonation is active.
     */
    public function isImpersonating(): bool
    {
        return $this->impersonationAuthenticator()?->isImpersonating() ?? false;
    }

    /**
     * Checks whether the current user is logged in.
     *
     * @return bool Whether the current user is logged in.
     */
    public function isLoggedIn(): bool
    {
        return (bool) $this->user;
    }

    /**
     * Logs in a user.
     *
     * @param Entity $user The Entity.
     * @param bool $rememberMe Whether to remember the user.
     * @return static The Auth instance.
     */
    public function login(Entity $user, bool $rememberMe = false): static
    {
        $this->successfulAuthenticator = null;
        $this->user = $user;

        foreach ($this->authenticators as $authenticator) {
            $authenticator->login($user, $rememberMe);
        }

        return $this;
    }

    /**
     * Logs out the current user.
     *
     * Note: All configured authenticators are notified so they can clear any persisted state.
     *
     * @return static The Auth instance.
     */
    public function logout(): static
    {
        $this->successfulAuthenticator = null;
        $this->user = null;

        foreach ($this->authenticators as $authenticator) {
            $authenticator->logout();
        }

        return $this;
    }

    /**
     * Stops impersonating the current user.
     *
     * Note: The original identity is restored on the next request.
     *
     * @return static The Auth instance.
     */
    public function stopImpersonating(): static
    {
        $authenticator = $this->impersonationAuthenticator();

        if ($authenticator?->isImpersonating()) {
            $authenticator->stopImpersonating();
        }

        return $this;
    }

    /**
     * Returns the current user.
     *
     * @return Entity|null The Entity instance for the current user or null if no user is logged in.
     */
    public function user(): Entity|null
    {
        return $this->user;
    }

    /**
     * Returns the Authenticator that established the current identity when it supports impersonation.
     *
     * @return ImpersonationAuthenticatorInterface|null The Authenticator instance, or null if it does not support impersonation.
     */
    protected function impersonationAuthenticator(): ImpersonationAuthenticatorInterface|null
    {
        return $this->successfulAuthenticator instanceof ImpersonationAuthenticatorInterface ?
            $this->successfulAuthenticator :
            null;
    }
}
