<?php
declare(strict_types=1);

namespace Fyre\Auth\Authenticators;

use Fyre\Auth\Authenticator;
use Fyre\Auth\StatelessAuthenticatorInterface;
use Fyre\ORM\Entity;
use Override;
use Psr\Http\Message\ServerRequestInterface;

use function is_string;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Authenticator that authenticates requests using stateless tokens.
 */
class TokenAuthenticator extends Authenticator implements StatelessAuthenticatorInterface
{
    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected static array $defaults = [
        'tokenHeader' => 'Authorization',
        'tokenHeaderPrefix' => 'Bearer',
        'tokenQuery' => null,
        'tokenField' => 'token',
    ];

    /**
     * {@inheritDoc}
     *
     * Extracts the token from the configured request header (optionally stripping a prefix) or query parameter.
     */
    #[Override]
    public function authenticate(ServerRequestInterface $request): Entity|null
    {
        $token = $this->config['tokenHeader'] ?
            $request->getHeaderLine($this->config['tokenHeader']) :
            null;

        if (
            $token &&
            $this->config['tokenHeaderPrefix'] &&
            str_starts_with($token, $this->config['tokenHeaderPrefix'].' ')
        ) {
            $token = substr($token, strlen($this->config['tokenHeaderPrefix']) + 1);
        }

        if ($token === '') {
            $token = null;
        }

        $tokenQuery = $this->config['tokenQuery'];
        $token ??= $tokenQuery ?
            $request->getQueryParams()[$tokenQuery] ?? null :
            null;

        if (!is_string($token) || $token === '') {
            return null;
        }

        return $this->auth->identifier()->identifyBy([$this->config['tokenField']], $token);
    }
}
