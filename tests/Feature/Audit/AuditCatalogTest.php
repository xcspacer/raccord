<?php

namespace Tests\Feature\Audit;

use App\Audit\AuditCatalog;
use App\Audit\AuditOperationException;
use App\Audit\AuditOperations;
use App\Audit\AuditSnapshot;
use App\Audit\OpenAudit;
use App\Enums\AuditCatalogList;
use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Livewire\Audit\AuditCatalogPage;
use App\Models\Agency;
use App\Models\Audit;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuditCatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $technician;

    private Audit $audit;

    protected function setUp(): void
    {
        parent::setUp();

        config(['audit.box_suffixes' => ['FT']]);
        $this->seed(RoleSeeder::class);
        $agency = Agency::factory()->create(['planning_mode' => PlanningMode::Daily]);
        $this->manager = User::factory()->withRole(Role::Supervisor)->create(['agency_id' => $agency->id]);
        $this->technician = User::factory()->withRole(Role::Technician)->create(['agency_id' => $agency->id]);
        $this->audit = app(OpenAudit::class)->handle($this->manager, 'PM153');
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function send(User $user, array $op): void
    {
        $this->actingAs($user);
        app(AuditOperations::class)->apply($user, $this->audit->refresh(), 'o_'.bin2hex(random_bytes(5)), $op);
    }

    private function reason(User $user, array $op): string
    {
        try {
            $this->send($user, $op);
        } catch (AuditOperationException $exception) {
            return $exception->reason;
        }

        return 'none';
    }

    private function closableChamber(): void
    {
        $this->send($this->technician, ['op' => 'box_add', 'name' => 'BPE-0001', 'support' => 'chamber']);
        $this->send($this->technician, ['op' => 'box_note', 'box' => 'BPE-0001', 'key' => 'before', 'text' => 'Clean']);
        $this->send($this->technician, ['op' => 'box_note', 'box' => 'BPE-0001', 'key' => 'route', 'text' => 'RAS']);
    }

    public function test_the_text_of_a_list_is_parsed_line_by_line_with_clear_errors(): void
    {
        $catalog = app(AuditCatalog::class);

        $this->assertSame(
            [['id' => 'A-1', 'label' => 'First'], ['id' => 'B_2', 'label' => 'Second | with bar']],
            $catalog->parse("A-1 | First\n\n B_2 |Second | with bar\n")['items'],
        );
        $this->assertSame(['invalid_line', 2], [$catalog->parse("A-1 | First\nno separator")['error'], $catalog->parse("A-1 | First\nno separator")['line']]);
        $this->assertSame('invalid_line', $catalog->parse('bad code | Label')['error']);
        $this->assertSame(['duplicate_code', 2], [$catalog->parse("A | One\nA | Two")['error'], $catalog->parse("A | One\nA | Two")['line']]);
        $this->assertSame('too_many', $catalog->parse(implode("\n", array_map(fn (int $n) => "C{$n} | Label", range(1, AuditCatalog::MAX_ITEMS + 1))))['error']);
    }

    public function test_replacing_a_list_keeps_the_order_and_the_snapshot_carries_the_lists(): void
    {
        $catalog = app(AuditCatalog::class);
        $catalog->replace(AuditCatalogList::Chamber, [['id' => 'B', 'label' => 'Second'], ['id' => 'A', 'label' => 'First']]);
        $catalog->replace(AuditCatalogList::Chamber, [['id' => 'Z', 'label' => 'Zed'], ['id' => 'A', 'label' => 'First']]);

        $this->assertSame("Z | Zed\nA | First", $catalog->toText(AuditCatalogList::Chamber));
        $this->assertSame([], $catalog->items(AuditCatalogList::Pole));
        $this->assertSame('Z', app(AuditSnapshot::class)->for($this->audit->refresh(), $this->technician)['catalog']['chamber'][0]['id']);
    }

    public function test_only_managers_edit_the_lists_and_invalid_text_is_refused_as_a_whole(): void
    {
        Livewire::actingAs($this->technician)->test(AuditCatalogPage::class)->assertForbidden();

        Livewire::actingAs($this->manager)->test(AuditCatalogPage::class)
            ->set('texts.chamber', "A | One\nA | Two")
            ->set('texts.pole', 'P | Pole')
            ->call('save')
            ->assertHasErrors(['texts.chamber']);
        $this->assertSame([], app(AuditCatalog::class)->items(AuditCatalogList::Pole));

        Livewire::actingAs($this->manager)->test(AuditCatalogPage::class)
            ->set('texts.chamber', 'A | One')
            ->set('texts.pole', 'P | Pole')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: __('audit.catalog.saved'));
        $this->assertSame([['id' => 'P', 'label' => 'Pole']], app(AuditCatalog::class)->items(AuditCatalogList::Pole));
    }

    public function test_a_technician_cannot_close_a_box_without_its_comments_nor_its_report_once_the_list_exists(): void
    {
        $this->send($this->technician, ['op' => 'box_add', 'name' => 'BPE-0001', 'support' => 'chamber']);
        $this->assertSame('before_required', $this->reason($this->technician, ['op' => 'box_status', 'box' => 'BPE-0001', 'status' => 'ok']));

        $this->send($this->technician, ['op' => 'box_note', 'box' => 'BPE-0001', 'key' => 'before', 'text' => 'Clean']);
        $this->assertSame('route_required', $this->reason($this->technician, ['op' => 'box_status', 'box' => 'BPE-0001', 'status' => 'anomaly', 'reason' => 'Cracked']));

        $this->send($this->technician, ['op' => 'box_note', 'box' => 'BPE-0001', 'key' => 'route', 'text' => 'RAS']);
        app(AuditCatalog::class)->replace(AuditCatalogList::Chamber, [['id' => 'A', 'label' => 'First']]);
        $this->assertSame('report_required', $this->reason($this->technician, ['op' => 'box_status', 'box' => 'BPE-0001', 'status' => 'ok']));

        $this->send($this->technician, ['op' => 'box_cr', 'box' => 'BPE-0001', 'items' => [['id' => 'A', 'label' => 'First', 'qty' => 1], ['id' => '', 'label' => 'x', 'qty' => 1]]]);
        $this->assertEquals([['id' => 'A', 'label' => 'First', 'qty' => 1]], $this->audit->boxes()->firstOrFail()->report_items);
        $this->assertSame('none', $this->reason($this->technician, ['op' => 'box_status', 'box' => 'BPE-0001', 'status' => 'ok']));
    }

    public function test_a_manager_closes_a_box_without_those_elements_and_a_pole_uses_its_own_list(): void
    {
        app(AuditCatalog::class)->replace(AuditCatalogList::Chamber, [['id' => 'A', 'label' => 'First']]);
        $this->send($this->technician, ['op' => 'box_add', 'name' => 'PB0002', 'support' => 'pole']);
        $this->send($this->technician, ['op' => 'box_note', 'box' => 'PB0002', 'key' => 'before', 'text' => 'Clean']);
        $this->send($this->technician, ['op' => 'box_note', 'box' => 'PB0002', 'key' => 'route', 'text' => 'RAS']);

        $this->assertSame('none', $this->reason($this->technician, ['op' => 'box_status', 'box' => 'PB0002', 'status' => 'ok']));

        $this->send($this->technician, ['op' => 'box_add', 'name' => 'BPE-0003', 'support' => 'chamber']);
        $this->assertSame('none', $this->reason($this->manager, ['op' => 'box_status', 'box' => 'BPE-0003', 'status' => 'ok']));
    }

    public function test_finishing_needs_the_cabinet_report_when_its_list_exists(): void
    {
        $this->closableChamber();
        $cabinet = $this->audit->controlPoints()->where('kind', 'cabinet')->firstOrFail();
        $this->assertSame('none', $this->reason($this->technician, ['op' => 'cp_note', 'point' => $cabinet->id, 'text' => 'Seen']));

        app(AuditCatalog::class)->replace(AuditCatalogList::Cabinet, [['id' => 'C', 'label' => 'Cabinet article']]);
        $this->assertSame('cabinet_report_required', $this->reason($this->technician, ['op' => 'finish']));

        $this->send($this->technician, ['op' => 'cp_cr', 'point' => $cabinet->id, 'items' => [['id' => 'C', 'label' => 'Cabinet article', 'qty' => 2]]]);
        $this->assertSame('none', $this->reason($this->technician, ['op' => 'finish']));
        $this->assertSame(2, $cabinet->refresh()->report_items[0]['qty']);
    }
}
