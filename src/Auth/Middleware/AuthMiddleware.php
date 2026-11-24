<?php
declare(strict_types=1);

namespace Fyre\Auth\Middleware;

use Fyre\Auth\Auth;
use Fyre\Core\Traits\DebugTrait;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HTTP middleware that runs authentication and attaches the user to the request.
 */
class AuthMiddleware implements MiddlewareInterface
{
    use DebugTrait;

    /**
     * Constructs an AuthMiddleware.
     *
     * @param Auth $auth The Auth.
     */
    public function __construct(
        protected Auth $auth
    ) {}

    /**
     * {@inheritDoc}
     *
     * Note: The `auth` and `user` attributes are added to the request. After the handler runs,
     * `beforeResponse()` is called on all configured authenticators with the current user from Auth.
     */
    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $request = $request->withAttribute('auth', $this->auth);

        $authenticators = $this->auth->authenticators();
        $response = $this->auth->authenticate($request)
            |> $request->withAttribute('user', ?)
            |> $handler->handle(...);

        $user = $this->auth->user();

        foreach ($authenticators as $authenticator) {
            $response = $authenticator->beforeResponse($response, $user);
        }

        return $response;
    }
}
