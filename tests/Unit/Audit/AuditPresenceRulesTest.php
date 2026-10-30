<?php

namespace Tests\Unit\Audit;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Checks the rules that announce a technician on a box from their GPS position (distances and thresholds of the
 * phone's code), by running that code on fixed cases.
 */
class AuditPresenceRulesTest extends TestCase
{
    public function test_distances_thresholds_and_repeats(): void
    {
        $box = fn (string $name, float $lat, float $lng, ?string $status = null, bool $confirmed = false) => [
            'name' => $name, 'status' => $status, 'returned' => null, 'removed' => false,
            $confirmed ? 'confirmed' : 'position' => ['latitude' => $lat, 'longitude' => $lng],
        ];
        $near = $box('A', 43.9300, 2.1480);
        $far = $box('B', 43.9400, 2.1480);
        $done = $box('C', 43.9298, 2.1480, 'ok');
        $at = fn (float $lat, float $lng, int $accuracy = 10) => ['latitude' => $lat, 'longitude' => $lng, 'accuracy' => $accuracy];

        $cases = [
            ['kind' => 'distance', 'a' => ['latitude' => 43.93, 'longitude' => 2.148], 'b' => ['latitude' => 43.93, 'longitude' => 2.148]],
            ['kind' => 'distance', 'a' => ['latitude' => 43.93, 'longitude' => 2.148], 'b' => ['latitude' => 43.94, 'longitude' => 2.148]],
            // On the box: 22 m away and accurate.
            ['kind' => 'presence', 'input' => ['fix' => $at(43.9298, 2.1480), 'boxes' => [$near, $far], 'announced' => null, 'openName' => 'A', 'now' => 1000]],
            // Too imprecise to say.
            ['kind' => 'presence', 'input' => ['fix' => $at(43.9298, 2.1480, 300), 'boxes' => [$near, $far], 'announced' => null, 'openName' => 'A', 'now' => 1000]],
            // 200 m from the box: neither on it nor far enough to leave.
            ['kind' => 'presence', 'input' => ['fix' => $at(43.9318, 2.1480), 'boxes' => [$near], 'announced' => ['box' => 'A', 'state' => 'on_box', 'at' => 0], 'openName' => 'A', 'now' => 1000]],
            // Moved 500 m away: back on the way.
            ['kind' => 'presence', 'input' => ['fix' => $at(43.9345, 2.1480), 'boxes' => [$near], 'announced' => ['box' => 'A', 'state' => 'on_box', 'at' => 0], 'openName' => 'A', 'now' => 1000]],
            // Already announced on that box a moment ago: nothing; two minutes later: announced again.
            ['kind' => 'presence', 'input' => ['fix' => $at(43.9298, 2.1480), 'boxes' => [$near], 'announced' => ['box' => 'A', 'state' => 'on_box', 'at' => 1000], 'openName' => 'A', 'now' => 60000]],
            ['kind' => 'presence', 'input' => ['fix' => $at(43.9298, 2.1480), 'boxes' => [$near], 'announced' => ['box' => 'A', 'state' => 'on_box', 'at' => 1000], 'openName' => 'A', 'now' => 130000]],
            // A finished box is ignored unless it is the open one.
            ['kind' => 'presence', 'input' => ['fix' => $at(43.9298, 2.1480), 'boxes' => [$done, $far], 'announced' => null, 'openName' => 'B', 'now' => 1000]],
            ['kind' => 'presence', 'input' => ['fix' => $at(43.9298, 2.1480), 'boxes' => [$done, $far], 'announced' => null, 'openName' => 'C', 'now' => 1000]],
            // The position confirmed on site counts when the plans have none.
            ['kind' => 'presence', 'input' => ['fix' => $at(43.9298, 2.1480), 'boxes' => [$box('D', 43.9300, 2.1480, null, true)], 'announced' => null, 'openName' => 'D', 'now' => 1000]],
            ['kind' => 'manual', 'fix' => $at(43.9318, 2.1480), 'box' => $near],
            ['kind' => 'manual', 'fix' => null, 'box' => $near],
            ['kind' => 'manual', 'fix' => $at(43.9318, 2.1480), 'box' => ['name' => 'E', 'status' => null]],
        ];

        $process = new Process(['node', base_path('tests/Support/audit-presence.mjs')], base_path(), null, json_encode($cases));
        $process->mustRun();
        $result = json_decode($process->getOutput(), true);

        $this->assertSame(0, $result[0]);
        $this->assertEqualsWithDelta(1112, $result[1], 5);
        $this->assertSame(['state' => 'on_box', 'box' => 'A'], $result[2]);
        $this->assertNull($result[3]);
        $this->assertNull($result[4]);
        $this->assertSame(['state' => 'en_route', 'box' => 'A'], $result[5]);
        $this->assertNull($result[6]);
        $this->assertSame(['state' => 'on_box', 'box' => 'A'], $result[7]);
        $this->assertNull($result[8]);
        $this->assertSame(['state' => 'on_box', 'box' => 'C'], $result[9]);
        $this->assertSame(['state' => 'on_box', 'box' => 'D'], $result[10]);
        $this->assertEqualsWithDelta(200, $result[11], 3);
        $this->assertNull($result[12]);
        $this->assertNull($result[13]);
    }
}
