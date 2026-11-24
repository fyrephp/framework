<?php
declare(strict_types=1);

namespace Fyre\Security\Middleware;

use Fyre\Core\Container;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Security\Cors;
use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HTTP middleware that applies Cross-Origin Resource Sharing (CORS) policy.
 */
class CorsMiddleware implements MiddlewareInterface
{
    use DebugTrait;

    protected Cors $cors;

    protected ResponseFactoryInterface $responseFactory;

    /**
     * Constructs a CorsMiddleware.
     *
     * @param Container $container The Container.
     * @param array<string, mixed>|null $options The CORS options.
     */
    public function __construct(Container $container, array|null $options = null)
    {
        $this->cors = $container->build(Cors::class, ['options' => $options]);
        $this->responseFactory = $container->use(ResponseFactoryInterface::class);
    }

    /**
     * {@inheritDoc}
     *
     * Note: Preflight requests return an empty `204 No Content` response without invoking
     * the next handler.
     */
    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->cors->canHandleRequest($request) || $this->cors->shouldSkip($request)) {
            return $handler->handle($request);
        }

        if ($this->cors->isPreflightRequest($request)) {
            return $this->responseFactory->createResponse(204)
                |> $this->cors->addHeadersPreflight($request, ?);
        }

        return $this->cors->addHeaders($request, $handler->handle($request));
    }
}
