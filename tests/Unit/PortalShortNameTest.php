<?php

namespace Tests\Unit;

use App\ClientPortal\PortalOverview;
use PHPUnit\Framework\TestCase;

class PortalShortNameTest extends TestCase
{
    public function test_staff_are_named_by_first_name_and_initial(): void
    {
        $this->assertSame('Jane D.', PortalOverview::shortName('jane doe'));
        $this->assertSame('Élise M.', PortalOverview::shortName('Élise Martin'));
        $this->assertSame('Jane', PortalOverview::shortName('Jane'));
    }

    public function test_an_anonymised_name_is_shown_whole(): void
    {
        $this->assertSame('Former employee #12', PortalOverview::shortName('Former employee #12'));
    }
}
