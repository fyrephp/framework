<?php
declare(strict_types=1);

namespace Tests\TestCase\Core;

use Closure;
use Exception;
use Fyre\Core\Config;
use Fyre\Core\Container;
use Fyre\Core\ErrorHandler;
use Fyre\Core\Exceptions\ErrorException;
use Fyre\Core\Traits\DebugTrait;
use Fyre\Event\Event;
use Fyre\Event\EventManager;
use Fyre\Http\ClientResponse;
use Fyre\Http\Exceptions\BadRequestException;
use Fyre\Http\Exceptions\ConflictException;
use Fyre\Http\Exceptions\ForbiddenException;
use Fyre\Http\Exceptions\GoneException;
use Fyre\Http\Exceptions\InternalServerException;
use Fyre\Http\Exceptions\MethodNotAllowedException;
use Fyre\Http\Exceptions\NotAcceptableException;
use Fyre\Http\Exceptions\NotFoundException;
use Fyre\Http\Exceptions\NotImplementedException;
use Fyre\Http\Exceptions\ServiceUnavailableException;
use Fyre\Http\Exceptions\UnauthorizedException;
use Fyre\Http\ResponseEmitter;
use Fyre\Log\Handlers\ArrayLogger;
use Fyre\Log\LogManager;
use Fyre\ORM\Exceptions\RecordNotFoundException;
use Override;
use PHPUnit\Framework\TestCase;
use Throwable;

use function class_uses;
use function escapeshellarg;
use function exec;
use function htmlspecialchars;
use function implode;
use function trigger_error;

use const E_USER_WARNING;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const PHP_BINARY;

final class ErrorHandlerTest extends TestCase
{
    protected Container $container;

    protected ErrorHandler $errorHandler;

    public function testBadRequest(): void
    {
        $response = $this->errorHandler->render(new BadRequestException());

        $this->assertSame(
            400,
            $response->getStatusCode()
        );
    }

    public function testConflict(): void
    {
        $response = $this->errorHandler->render(new ConflictException());

        $this->assertSame(
            409,
            $response->getStatusCode()
        );
    }

    public function testDebug(): void
    {
        $this->assertContains(
            DebugTrait::class,
            class_uses(ErrorHandler::class)
        );
    }

    public function testDestruct(): void
    {
        $this->errorHandler->register();
        $this->errorHandler->__destruct();

        $registered = Closure::bind(function(): bool {
            /** @var ErrorHandler $this */
            return $this->registered;
        }, $this->errorHandler, ErrorHandler::class)();

        $this->assertFalse($registered);
    }

    public function testEnableCli(): void
    {
        $this->assertSame(
            $this->errorHandler,
            $this->errorHandler->enableCli()
        );

        $cli = Closure::bind(function(): bool {
            /** @var ErrorHandler $this */
            return $this->cli;
        }, $this->errorHandler, ErrorHandler::class)();

        $this->assertTrue($cli);
    }

    public function testEventBeforeRender(): void
    {
        $ran = false;
        $this->errorHandler->getEventManager()->on('Error.beforeRender', function(Event $event, Throwable $exception) use (&$ran): void {
            $ran = true;

            $this->assertInstanceOf(ConflictException::class, $exception);
        });

        $this->errorHandler->render(new ConflictException());

        $this->assertTrue($ran);
    }

    public function testForbidden(): void
    {
        $response = $this->errorHandler->render(new ForbiddenException());

        $this->assertSame(
            403,
            $response->getStatusCode()
        );
    }

    public function testGetException(): void
    {
        $exception = new Exception('Error');

        $this->assertNull(
            $this->errorHandler->getException()
        );

        $this->errorHandler->render($exception);

        $this->assertSame(
            $exception,
            $this->errorHandler->getException()
        );
    }

    public function testGone(): void
    {
        $response = $this->errorHandler->render(new GoneException());

        $this->assertSame(
            410,
            $response->getStatusCode()
        );
    }

    public function testHandle(): void
    {
        $exception = new Exception('Sensitive error');
        $response = $this->errorHandler->render($exception);

        $this->assertInstanceOf(
            ClientResponse::class,
            $response
        );

        $this->assertSame(
            500,
            $response->getStatusCode()
        );

        $this->assertSame(
            'Internal Server Error',
            $response->getBody()->getContents()
        );
    }

    public function testHandleCli(): void
    {
        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../../Mock/Core/ErrorHandler/exception.php').' 2>&1',
            $output,
            $exitCode
        );

        $this->assertSame(
            1,
            $exitCode
        );

        $this->assertStringContainsString(
            'RuntimeException: CLI failure',
            implode("\n", $output)
        );
    }

    public function testHandleDebug(): void
    {
        $this->container->use(Config::class)->set('App.debug', true);

        $errorHandler = $this->container->build(ErrorHandler::class);
        $errorHandler->disableCli();

        $exception = new Exception('<script>Sensitive error</script>');
        $response = $errorHandler->render($exception);
        $this->assertSame(
            500,
            $response->getStatusCode()
        );

        $this->assertSame(
            '<pre>'.htmlspecialchars((string) $exception, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</pre>',
            $response->getBody()->getContents()
        );
    }

    public function testInternalServer(): void
    {
        $response = $this->errorHandler->render(new InternalServerException());

        $this->assertSame(
            500,
            $response->getStatusCode()
        );
    }

    public function testLogEnabled(): void
    {
        $this->container->singleton(LogManager::class);
        $this->container->use(Config::class)
            ->set('Error', [
                'log' => true,
            ])
            ->set('Log', [
                'default' => [
                    'className' => ArrayLogger::class,
                ],
            ]);

        $errorHandler = $this->container->build(ErrorHandler::class);
        $errorHandler->disableCli();

        $exception = new Exception('Error');
        $errorHandler->render($exception);

        $logger = $this->container->use(LogManager::class)->use();

        $this->assertInstanceOf(
            ArrayLogger::class,
            $logger
        );

        $this->assertArraysAreIdentical(
            [
                '[ERROR] '.(string) $exception,
            ],
            $logger->read()
        );
    }

    public function testMethodNotAllowed(): void
    {
        $response = $this->errorHandler->render(new MethodNotAllowedException());

        $this->assertSame(
            405,
            $response->getStatusCode()
        );
    }

    public function testNotAcceptable(): void
    {
        $response = $this->errorHandler->render(new NotAcceptableException());

        $this->assertSame(
            406,
            $response->getStatusCode()
        );
    }

    public function testNotFound(): void
    {
        $response = $this->errorHandler->render(new NotFoundException());

        $this->assertSame(
            404,
            $response->getStatusCode()
        );
    }

    public function testNotImplemented(): void
    {
        $response = $this->errorHandler->render(new NotImplementedException());

        $this->assertSame(
            501,
            $response->getStatusCode()
        );
    }

    public function testRecordNotFound(): void
    {
        $exception = new RecordNotFoundException();
        $response = $this->errorHandler->render($exception);

        $this->assertSame(
            404,
            $response->getStatusCode()
        );
    }

    public function testRegister(): void
    {
        $this->errorHandler->register();
        $this->errorHandler->register();

        $registered = Closure::bind(function(): bool {
            /** @var ErrorHandler $this */
            return $this->registered;
        }, $this->errorHandler, ErrorHandler::class)();

        $this->assertTrue($registered);

        $this->errorHandler->unregister();

        $registered = Closure::bind(function(): bool {
            /** @var ErrorHandler $this */
            return $this->registered;
        }, $this->errorHandler, ErrorHandler::class)();

        $this->assertFalse($registered);
    }

    public function testRegisteredErrorHandlerThrows(): void
    {
        $this->errorHandler->register();

        try {
            trigger_error('PHP warning', E_USER_WARNING);
            $this->fail('Execution continued after a reported PHP error.');
        } catch (ErrorException $exception) {
            $this->assertSame(
                'PHP warning',
                $exception->getMessage()
            );

            $this->assertSame(
                E_USER_WARNING,
                $exception->getSeverity()
            );
        }
    }

    public function testRenderer(): void
    {
        $ran = false;
        $renderer = static function(Throwable $exception) use (&$ran): string {
            $ran = true;

            return $exception->getMessage();
        };

        $this->assertSame(
            $this->errorHandler,
            $this->errorHandler->setRenderer($renderer)
        );

        $this->assertSame(
            $renderer,
            $this->errorHandler->getRenderer()
        );

        $exception = new Exception('Error');
        $response = $this->errorHandler->render($exception);

        $this->assertTrue($ran);

        $this->assertSame(
            'Error',
            $response->getBody()->getContents()
        );
    }

    public function testRendererResponse(): void
    {
        $response = new ClientResponse([
            'body' => 'Error',
            'headers' => [
                'X-Test' => 'test',
            ],
        ]);

        $this->errorHandler->setRenderer(
            fn(Throwable $exception): ClientResponse => $response
        );

        $response = $this->errorHandler->render(
            new MethodNotAllowedException(headers: [
                'Allow' => 'GET',
            ])
        );

        $this->assertSame(
            405,
            $response->getStatusCode()
        );

        $this->assertSame(
            'test',
            $response->getHeaderLine('X-Test')
        );

        $this->assertSame(
            'GET',
            $response->getHeaderLine('Allow')
        );

        $this->assertSame(
            'Error',
            $response->getBody()->getContents()
        );
    }

    public function testServiceUnavailable(): void
    {
        $response = $this->errorHandler->render(new ServiceUnavailableException());

        $this->assertSame(
            503,
            $response->getStatusCode()
        );
    }

    public function testUnauthorized(): void
    {
        $response = $this->errorHandler->render(new UnauthorizedException());

        $this->assertSame(
            401,
            $response->getStatusCode()
        );
    }

    #[Override]
    protected function setUp(): void
    {
        $this->container = new Container();
        $this->container->singleton(Config::class);
        $this->container->singleton(EventManager::class);
        $this->container->singleton(ResponseEmitter::class);
        $this->container->use(Config::class)->set('Error', [
            'log' => false,
        ]);

        $this->errorHandler = $this->container->use(ErrorHandler::class);
        $this->errorHandler->disableCli();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->errorHandler->unregister();
    }
}
