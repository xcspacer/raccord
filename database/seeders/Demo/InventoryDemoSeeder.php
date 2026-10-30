<?php

namespace Database\Seeders\Demo;

use App\Actions\Inventory\AssignItem;
use App\Actions\Inventory\ConfirmItems;
use App\Actions\Inventory\CreateItems;
use App\Actions\Inventory\DeclareAnomaly;
use App\Enums\InventoryAnomalyKind;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Invented equipment for the demo: some in stock, some handed over, some noted but not confirmed, one anomaly.
 */
class InventoryDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (InventoryItem::query()->exists()) {
            return;
        }

        $manager = User::query()->where('username', 'supervisor')->firstOrFail();
        $first = User::query()->where('username', 'tech1')->firstOrFail();
        $second = User::query()->where('username', 'tech2')->firstOrFail();
        $create = app(CreateItems::class);

        $create->handle($manager, [
            ['label' => 'Fusion splicer', 'category' => 'welder', 'brand' => 'Acme', 'model' => 'FS-90', 'serial' => 'DEMO-0001', 'condition' => 'good'],
            ['label' => 'Optical power meter', 'category' => 'measurement', 'brand' => 'Acme', 'model' => 'PM-10', 'serial' => 'DEMO-0002', 'condition' => 'new'],
            ['label' => 'Hammer drill', 'category' => 'power_tool', 'brand' => 'Demo Tools', 'model' => 'HD-26', 'serial' => 'DEMO-0003', 'condition' => 'good'],
            ['label' => 'Telescopic ladder', 'category' => 'access', 'quantity' => 2, 'condition' => 'good'],
            ['label' => 'Safety gloves', 'category' => 'safety', 'quantity' => 12, 'condition' => 'new'],
        ], null, ['source' => 'Demo']);

        $create->handle($manager, [
            ['label' => 'Cleaver', 'category' => 'welder', 'brand' => 'Acme', 'model' => 'CL-3', 'serial' => 'DEMO-0004', 'condition' => 'good'],
            ['label' => 'Cable stripper kit', 'category' => 'hand_tool', 'condition' => 'good'],
        ], $first, ['official' => true, 'source' => 'Demo']);

        $create->handle($manager, [
            ['label' => 'Inspection scope', 'category' => 'measurement', 'brand' => 'Demo Optics', 'model' => 'IS-1', 'serial' => 'DEMO-0005', 'needs_check' => true, 'uncertainty' => 'Serial number hard to read'],
            ['label' => 'Safety helmet', 'category' => 'safety', 'quantity' => 2],
        ], $second, ['site' => 'Main depot', 'source' => 'Demo']);

        $assigned = app(AssignItem::class)->handle($manager, InventoryItem::query()->where('label', 'Safety gloves')->firstOrFail()->id, $first->id, 4, 'new');
        app(ConfirmItems::class)->handle($manager, [$assigned->id]);
        app(DeclareAnomaly::class)->handle($first, $assigned->id, InventoryAnomalyKind::Damaged, 'Torn during a job', 1);
    }
}
