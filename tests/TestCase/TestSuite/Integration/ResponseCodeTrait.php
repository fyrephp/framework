<?php
declare(strict_types=1);

namespace Tests\TestCase\TestSuite\Integration;

use Fyre\Http\Exceptions\HttpException;
use Fyre\Http\MiddlewareQueue;
use Fyre\Http\MiddlewareRegistry;
use PHPUnit\Framework\AssertionFailedError;

trait ResponseCodeTrait
{
    public function testCleanupRestoresRendering(): void
    {
        $this->disableErrorRendering();
        $this->disableErrorRendering();

        $this->cleanup();

        $this->get('/fail');

        $this->assertResponseCode(500);
    }

    public function testExceptionsPropagate(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessageIs('Internal Server Error');

        $this->disableErrorRendering();

        $this->get('/fail');
    }

    public function testExceptionsPropagateFromMiddlewareGroup(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessageIs('Internal Server Error');

        $this->app->use(MiddlewareRegistry::class)->group('web', ['error', 'router']);
        $this->app->replaceInstance(MiddlewareQueue::class, new MiddlewareQueue(['web']));

        $this->disableErrorRendering();

        $this->get('/fail');
    }

    public function testMultipleRequests(): void
    {
        $this->get('/response');

        $this->assertResponseCode(200);

        $this->get('/response');

        $this->assertResponseCode(200);
    }

    public function testRenderingCanBeEnabledAgain(): void
    {
        $this->disableErrorRendering();

        try {
            $this->get('/fail');

            $this->fail('Expected the request exception to propagate.');
        } catch (HttpException $e) {
            $this->assertSame('Internal Server Error', $e->getMessage());
        }

        $this->enableErrorRendering();

        $this->get('/fail');

        $this->assertResponseCode(500);
        $this->assertResponseContains('Internal Server Error');
    }

    public function testResponseCode(): void
    {
        $this->get('/response');

        $this->assertResponseCode(200);
    }

    public function testResponseCodeFail(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIs('Failed asserting that response status code is equal to "200".');

        $this->get('/error');

        $this->assertResponseCode(200);
    }

    public function testResponseCodeNoResponse(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIs('No response has been set.');

        $this->assertResponseCode(200);
    }

    public function testResponseError(): void
    {
        $this->get('/error');

        $this->assertResponseError();
    }

    public function testResponseErrorFail(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIs('Failed asserting that response status code is between 400 and 599.');

        $this->get('/response');

        $this->assertResponseError();
    }

    public function testResponseErrorNoResponse(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIs('No response has been set.');

        $this->assertResponseError();
    }

    public function testResponseFailure(): void
    {
        $this->get('/fail');

        $this->assertResponseFailure();
    }

    public function testResponseFailureExtendedStatus(): void
    {
        $this->get('/fail-extended');

        $this->assertResponseFailure();
    }

    public function testResponseFailureFail(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIs('Failed asserting that response status code is between 500 and 599.');

        $this->get('/response');

        $this->assertResponseFailure();
    }

    public function testResponseFailureNoResponse(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIs('No response has been set.');

        $this->assertResponseFailure();
    }

    public function testResponseOk(): void
    {
        $this->get('/response');

        $this->assertResponseOk();
    }

    public function testResponseOkFail(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIs('Failed asserting that response status code is between 200 and 204.');

        $this->get('/error');

        $this->assertResponseOk();
    }

    public function testResponseOkNoResponse(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIs('No response has been set.');

        $this->assertResponseOk();
    }

    public function testResponseSuccess(): void
    {
        $this->get('/response');

        $this->assertResponseSuccess();
    }

    public function testResponseSuccessFail(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIs('Failed asserting that response status code is between 200 and 308.');

        $this->get('/error');

        $this->assertResponseSuccess();
    }

    public function testResponseSuccessNoResponse(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIs('No response has been set.');

        $this->assertResponseSuccess();
    }
}
