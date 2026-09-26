<?php
declare(strict_types=1);

namespace Tests\TestCase\TestSuite\PhpStan;

use Override;
use PHPStan\Testing\TypeInferenceTestCase;

use const ROOT;

final class TypeParserUseReturnTypeExtensionTest extends TypeInferenceTestCase
{
    /**
     * @return string[]
     */
    #[Override]
    public static function getAdditionalConfigFiles(): array
    {
        return [ROOT.'/tests/Mock/PhpStan/phpstan.neon'];
    }

    public function testReturnTypes(): void
    {
        // Data providers run before PHPUnit starts coverage.
        $assertions = self::gatherAssertTypes(ROOT.'/tests/Mock/PhpStan/TypeParserUse.php');

        foreach ($assertions as $assertion) {
            $this->assertFileAsserts(...$assertion);
        }
    }
}
