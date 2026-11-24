<?php
declare(strict_types=1);

namespace Tests\Mock\Http;

use Fyre\Http\Cookie\CookieJar;
use Override;

class TestCookieJar extends CookieJar
{
    #[Override]
    protected const MAX_COOKIES = 3;

    #[Override]
    protected const MAX_COOKIES_PER_DOMAIN = 3;
}
