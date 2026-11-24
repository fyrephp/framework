<?php
declare(strict_types=1);

namespace Tests\TestCase\Http\ClientResponse;

use Closure;
use Fyre\Http\ClientResponse;
use Fyre\Utility\DateTime\DateTime;
use PHPUnit\Framework\Attributes\DataProvider;

trait DateTestTrait
{
    /**
     * @return array<string, array{Closure(): (DateTime|\DateTime|string)}>
     */
    public static function dateRepresentationProvider(): array
    {
        return [
            'timestamp string' => [static fn(): string => '0'],
            'framework date time' => [DateTime::createFromTimestamp(0, ...)],
            'native date time' => [static fn(): \DateTime => new \DateTime('@0')],
        ];
    }

    /**
     * @param Closure(): (DateTime|\DateTime|string) $date
     */
    #[DataProvider('dateRepresentationProvider')]
    public function testWithDate(Closure $date): void
    {
        $response1 = new ClientResponse();
        $response2 = $date() |> $response1->withDate(...);

        $this->assertSame(
            '',
            $response1->getHeaderLine('Date')
        );

        $this->assertSame(
            'Thu, 01-Jan-1970 00:00:00 UTC',
            $response2->getHeaderLine('Date')
        );
    }

    /**
     * @param Closure(): (DateTime|\DateTime|string) $date
     */
    #[DataProvider('dateRepresentationProvider')]
    public function testWithLastModified(Closure $date): void
    {
        $response1 = new ClientResponse();
        $response2 = $date() |> $response1->withLastModified(...);

        $this->assertSame(
            '',
            $response1->getHeaderLine('Last-Modified')
        );

        $this->assertSame(
            'Thu, 01-Jan-1970 00:00:00 UTC',
            $response2->getHeaderLine('Last-Modified')
        );
    }
}
