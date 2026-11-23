<?php
declare(strict_types=1);

namespace Fyre\Auth\Authenticators;

use Fyre\Auth\Authenticator;
use Fyre\Core\Attributes\SensitivePropertyArray;
use Fyre\Http\Cookie\Cookie;
use Fyre\ORM\Entity;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function array_is_list;
use function count;
use function hash;
use function hash_hmac;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function password_hash;
use function password_needs_rehash;
use function password_verify;
use function rawurldecode;
use function rawurlencode;
use function time;

use const PASSWORD_DEFAULT;

/**
 * Authenticator that persists identity via cookies.
 */
class CookieAuthenticator extends Authenticator
{
    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected static array $defaults = [
        'cookieName' => 'auth',
        'cookieOptions' => [
            'httpOnly' => true,
        ],
        'identifierField' => 'email',
        'passwordField' => 'password',
        'salt' => null,
    ];

    /**
     * @var array<string, mixed>
     */
    #[Override]
    #[SensitivePropertyArray(['salt'])]
    protected array $config;

    protected Entity|null $cookieUser = null;

    protected bool|null $sendCookie = null;

    /**
     * {@inheritDoc}
     *
     * Note: When the cookie is invalid, this authenticator marks it for deletion on the next response.
     */
    #[Override]
    public function authenticate(ServerRequestInterface $request): Entity|null
    {
        $cookieName = $this->config['cookieName'];
        $cookie = $request->getCookieParams()[$cookieName] ?? null;

        if ($cookie === null || $cookie === '') {
            return null;
        }

        if (!is_string($cookie)) {
            $this->logout();

            return null;
        }

        $data = json_decode(rawurldecode($cookie), true);

        if (
            !is_array($data) ||
            !array_is_list($data) ||
            count($data) !== 2 ||
            !is_string($data[0]) ||
            !is_string($data[1])
        ) {
            $this->logout();

            return null;
        }

        [$identifier, $tokenHash] = $data;

        if (password_needs_rehash($tokenHash, PASSWORD_DEFAULT)) {
            $this->logout();

            return null;
        }

        $user = $this->auth->identifier()->identify($identifier);

        if (!$user) {
            $this->logout();

            return null;
        }

        $token = $this->createToken($user);

        if ($token === null || !password_verify($token, $tokenHash)) {
            $this->logout();

            return null;
        }

        return $user;
    }

    /**
     * {@inheritDoc}
     *
     * Writes a cookie for the user queued by login, or clears the cookie after logout.
     */
    #[Override]
    public function beforeResponse(ResponseInterface $response, Entity|null $user = null): ResponseInterface
    {
        if ($this->sendCookie === null) {
            return $response;
        }

        $cookieOptions = $this->config['cookieOptions'];
        $value = '';

        if ($this->cookieUser && ($token = $this->createToken($this->cookieUser)) !== null) {
            $identifier = $this->cookieUser->get($this->config['identifierField']);
            $tokenHash = password_hash($token, PASSWORD_DEFAULT);

            $value = (string) json_encode([$identifier, $tokenHash]) |> rawurlencode(...);

            if (isset($cookieOptions['expires'])) {
                $cookieOptions['expires'] += time();
            }
        } else {
            $cookieOptions['expires'] = 1;
        }

        return $response->withAddedHeader(
            'Set-Cookie',
            new Cookie($this->config['cookieName'], $value, $cookieOptions)->toHeaderString()
        );
    }

    /**
     * {@inheritDoc}
     *
     * Note: The cookie is only written when `$rememberMe` is true.
     */
    #[Override]
    public function login(Entity $user, bool $rememberMe = false): void
    {
        if ($rememberMe) {
            $this->sendCookie = true;
            $this->cookieUser = $user;
        }
    }

    /**
     * {@inheritDoc}
     *
     * Marks the cookie for deletion on the next response.
     */
    #[Override]
    public function logout(): void
    {
        $this->sendCookie = false;
        $this->cookieUser = null;
    }

    /**
     * Creates a token for a user.
     *
     * Note: The token is derived from the configured identifier and password fields so it is invalidated
     * automatically when the stored password changes. A fixed-length SHA-256 digest (HMAC when a salt
     * is configured) keeps the token within bcrypt's 72-byte limit.
     *
     * @param Entity $user The Entity.
     * @return string|null The token, or null if the stored password hash is missing or empty.
     */
    protected function createToken(Entity $user): string|null
    {
        $identifier = $user->get($this->config['identifierField']);
        $password = $user->get($this->config['passwordField']);

        if (!is_string($password) || $password === '') {
            return null;
        }

        $value = $identifier.$password;

        if (!$this->config['salt']) {
            return hash('sha256', $value);
        }

        return hash_hmac('sha256', $value, $this->config['salt']);
    }
}
