<?php
declare(strict_types=1);

namespace Fyre\Auth\Authenticators;

use Fyre\Auth\Auth;
use Fyre\Auth\Authenticator;
use Fyre\Auth\ImpersonationAuthenticatorInterface;
use Fyre\Http\Session\Session;
use Fyre\ORM\Entity;
use InvalidArgumentException;
use LogicException;
use Override;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Authenticator that persists identity via the session.
 */
class SessionAuthenticator extends Authenticator implements ImpersonationAuthenticatorInterface
{
    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected static array $defaults = [
        'impersonatorSessionKey' => 'authImpersonator',
        'sessionKey' => 'auth',
        'sessionField' => 'id',
    ];

    /**
     * Constructs a SessionAuthenticator.
     *
     * @param Auth $auth The Auth.
     * @param Session $session The Session.
     * @param array<string, mixed> $options The Authenticator options.
     */
    public function __construct(
        Auth $auth,
        protected Session $session,
        array $options = []
    ) {
        parent::__construct($auth, $options);

        if ($this->config['sessionKey'] === $this->config['impersonatorSessionKey']) {
            throw new InvalidArgumentException('Session keys for authentication and impersonation must be different.');
        }
    }

    /**
     * {@inheritDoc}
     *
     * Reads the user identifier from the session and loads the user using the configured model.
     */
    #[Override]
    public function authenticate(ServerRequestInterface $request): Entity|null
    {
        return $this->session->get($this->config['sessionKey']) |> $this->findUser(...);
    }

    /**
     * {@inheritDoc}
     *
     * Stores the original and impersonated user identifiers and refreshes the session.
     *
     * @throws LogicException If impersonation is already active, the users match, or the current user is not authenticated through the session.
     */
    #[Override]
    public function impersonate(Entity $impersonator, Entity $user): void
    {
        if ($this->isImpersonating()) {
            throw new LogicException('A user is already being impersonated.');
        }

        $sessionField = $this->config['sessionField'];
        $impersonatorId = $impersonator->get($sessionField);
        $userId = $user->get($sessionField);

        if ($impersonatorId === $userId) {
            throw new LogicException('A user cannot impersonate themselves.');
        }

        if ($this->session->get($this->config['sessionKey']) !== $impersonatorId) {
            throw new LogicException('The current user is not authenticated through the session.');
        }

        $this->session->refresh(true);
        $this->session->set($this->config['impersonatorSessionKey'], $impersonatorId);
        $this->session->set($this->config['sessionKey'], $userId);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function impersonator(): Entity|null
    {
        if (!$this->isImpersonating()) {
            return null;
        }

        return $this->session->get($this->config['impersonatorSessionKey']) |> $this->findUser(...);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function isImpersonating(): bool
    {
        return $this->session->has($this->config['impersonatorSessionKey']);
    }

    /**
     * {@inheritDoc}
     *
     * Stores the user identifier and refreshes the session when the identity changes.
     */
    #[Override]
    public function login(Entity $user, bool $rememberMe = false): void
    {
        $sessionKey = $this->config['sessionKey'];
        $id = $user->get($this->config['sessionField']);

        if ($this->session->get($sessionKey) !== $id) {
            $this->session->refresh(true);
            $this->session->delete($this->config['impersonatorSessionKey']);
            $this->session->set($sessionKey, $id);
        }
    }

    /**
     * {@inheritDoc}
     *
     * Removes the session key and refreshes the session.
     */
    #[Override]
    public function logout(): void
    {
        $this->session->delete($this->config['sessionKey']);
        $this->session->delete($this->config['impersonatorSessionKey']);
        $this->session->refresh(true);
    }

    /**
     * {@inheritDoc}
     *
     * Restores the original user identifier and refreshes the session.
     */
    #[Override]
    public function stopImpersonating(): void
    {
        if (!$this->isImpersonating()) {
            return;
        }

        $id = $this->session->get($this->config['impersonatorSessionKey']);

        $this->session->refresh(true);
        $this->session->delete($this->config['impersonatorSessionKey']);
        $this->session->set($this->config['sessionKey'], $id);
    }

    /**
     * Finds a user by the configured session field.
     *
     * @param mixed $id The user identifier.
     * @return Entity|null The Entity instance, or null if the user does not exist.
     */
    protected function findUser(mixed $id): Entity|null
    {
        if (!$id) {
            return null;
        }

        return $this->auth->identifier()->identifyBy([$this->config['sessionField']], $id);
    }
}
