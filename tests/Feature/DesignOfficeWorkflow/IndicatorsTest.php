<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\BoxTeam;
use App\DesignOfficeWorkflow\ProductionTime;
use App\Enums\AuditFolder;
use App\Enums\Role;
use App\Livewire\DesignOfficeWorkflow\IndicatorsPage;
use App\Models\AuditDurationAdjustment;
use App\Models\AuditPause;
use App\Models\AuditPhoto;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;

/**
 * Declared pauses, production time (first photo → accepted submission, minus the design office waits and the pauses),
 * the supervisor's corrections that keep the computed value, and indicators each person sees only for themselves.
 */
class IndicatorsTest extends TestCase
{
    use DesignOfficeWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-30 08:00:00'));
        $this->setUpWorkflow();
    }

    public function test_a_team_member_declares_and_ends_a_pause_on_the_box(): void
    {
        $box = $this->readyBox();

        $this->actingAs($this->technician)->postJson(route('design-office.boxes.pause', $box), ['reason' => 'Lunch'])->assertCreated();
        $this->actingAs($this->technician)->postJson(route('design-office.boxes.pause', $box))->assertStatus(409)->assertJsonPath('reason', 'pause_open');
        $this->travel(12)->minutes();
        $this->actingAs($this->technician)->postJson(route('design-office.boxes.resume', $box))->assertOk();
        $this->actingAs($this->technician)->postJson(route('design-office.boxes.resume', $box))->assertStatus(409)->assertJsonPath('reason', 'no_open_pause');

        $pause = AuditPause::query()->sole();
        $this->assertSame(720, (int) $pause->started_at->diffInSeconds($pause->ended_at));
        $this->assertSame('Lunch', $pause->reason);
        $this->assertSame(['pause_started', 'pause_ended'], Activity::query()->where('log_name', 'design_office_workflow')->where('event', 'like', 'pause_%')->orderBy('id')->pluck('event')->all());

        $outsider = $this->person(Role::Technician);
        $this->actingAs($outsider)->postJson(route('design-office.boxes.pause', $box))->assertForbidden();
        $this->actingAs($this->reviewer)->postJson(route('design-office.boxes.pause', $box))->assertForbidden();
    }

    public function test_production_time_leaves_out_the_design_office_waits_and_the_pauses(): void
    {
        $box = $this->box();
        $this->fillPhotos($box);
        AuditPhoto::query()->where('audit_box_id', $box->id)->update(['captured_at' => now()]);
        app(BoxTeam::class)->claim($this->technician, $box);

        $this->travel(30)->minutes();
        $first = $this->submit($box->refresh());
        $this->travel(10)->minutes();
        $this->decide($first, 'refuse', targets: ['Redo the labels']);
        $this->travel(10)->minutes();
        AuditPause::query()->create(['user_id' => $this->technician->id, 'audit_box_id' => $box->id, 'started_at' => now(), 'ended_at' => now()->addMinutes(10)]);
        $this->travel(40)->minutes();
        $second = $this->submit($box->refresh());
        $this->travel(2)->minutes();
        $this->decide($second, 'validate', reason: null);

        $time = app(ProductionTime::class)->of($box->refresh());
        $this->assertSame(90 * 60, $time['gross']);
        $this->assertSame(10 * 60, $time['waiting']);
        $this->assertSame(10 * 60, $time['paused']);
        $this->assertSame(70 * 60, $time['computed']);
        $this->assertSame(70 * 60, $time['effective']);
        $this->assertSame($this->technician->id, $time['technician_id']);
    }

    public function test_a_supervisor_corrects_a_production_time_and_the_computed_value_is_kept(): void
    {
        $box = $this->readyBox();
        $this->travel(20)->minutes();
        $this->decide($this->submit($box), 'validate', reason: null);

        Livewire::actingAs($this->technician)->test(IndicatorsPage::class)->call('startAdjustment', $box->id)->assertForbidden();
        Livewire::actingAs($this->reviewer)->test(IndicatorsPage::class)->call('startAdjustment', $box->id)->assertForbidden();

        Livewire::actingAs($this->supervisor)->test(IndicatorsPage::class)
            ->call('startAdjustment', $box->id)->set('minutes', '45')->set('justification', 'no')->call('adjust')->assertHasErrors('justification')
            ->set('justification', 'Phone clock was wrong on site')->call('adjust')->assertHasNoErrors();

        $adjustment = AuditDurationAdjustment::query()->sole();
        $this->assertSame(20 * 60, $adjustment->original_seconds);
        $this->assertSame(45 * 60, $adjustment->adjusted_seconds);
        $time = app(ProductionTime::class)->of($box);
        $this->assertSame(20 * 60, $time['computed']);
        $this->assertSame(45 * 60, $time['effective']);
        $this->assertSame(1, Activity::query()->where('event', 'duration_adjusted')->count());
    }

    public function test_each_person_sees_only_their_own_indicators_and_supervisors_see_everyone(): void
    {
        $mine = $this->readyBox();
        $theirs = $this->readyBox($this->partner);
        $this->travel(15)->minutes();
        $this->decide($this->submit($mine), 'validate', $this->reviewer, null);
        $this->decide($this->submit($theirs, $this->partner), 'validate', $this->otherReviewer, null);

        $technicianView = Livewire::actingAs($this->technician)->test(IndicatorsPage::class);
        $technicianView->assertSee($this->technician->name)->assertDontSee($this->partner->name)->assertDontSee($this->reviewer->name)->assertSee($mine->name)->assertDontSee($theirs->name);

        Livewire::actingAs($this->reviewer)->test(IndicatorsPage::class)
            ->assertSee($this->reviewer->name)->assertDontSee($this->otherReviewer->name)->assertDontSee($this->technician->name);

        Livewire::actingAs($this->supervisor)->test(IndicatorsPage::class)
            ->assertSee($this->technician->name)->assertSee($this->partner->name)->assertSee($this->reviewer->name)->assertSee($this->otherReviewer->name);
    }

    public function test_the_indicators_need_the_workflow(): void
    {
        $this->actingAs($this->technician)->get(route('design-office.indicators'))->assertOk();

        $this->switchWorkflowModule(false);
        $this->actingAs($this->supervisor)->get(route('design-office.indicators'))->assertNotFound();
    }

    public function test_photos_imported_without_a_field_capture_do_not_start_the_clock(): void
    {
        $box = $this->readyBox();
        $imported = $this->photo($box, AuditFolder::Closed);
        $imported->forceFill(['is_imported' => true, 'captured_at' => now()->subDays(3)])->save();
        $this->travel(5)->minutes();
        $this->decide($this->submit($box), 'validate', reason: null);

        $this->assertSame(5 * 60, app(ProductionTime::class)->of($box)['computed']);
    }
}
