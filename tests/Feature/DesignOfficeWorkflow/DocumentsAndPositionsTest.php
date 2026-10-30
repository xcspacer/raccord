<?php

namespace Tests\Feature\DesignOfficeWorkflow;

use App\Audit\AuditOperations;
use App\Audit\AuditSnapshot;
use App\Audit\ConfigureAudit;
use App\DesignOfficeWorkflow\BoxPositions;
use App\DesignOfficeWorkflow\BoxTeam;
use App\DesignOfficeWorkflow\DocumentVersions;
use App\DesignOfficeWorkflow\SynopticCorrections;
use App\DesignOfficeWorkflow\WorkflowException;
use App\Enums\AuditDocumentKind;
use App\Enums\AuditFolder;
use App\Enums\AuditPositionSource;
use App\Enums\AuditSynopticCorrectionState;
use App\Enums\Role;
use App\Livewire\DesignOfficeWorkflow\BoxPositionsPanel;
use App\Livewire\DesignOfficeWorkflow\DocumentsPanel;
use App\Models\AuditBox;
use App\Models\AuditBoxPosition;
use App\Models\AuditDocumentVersion;
use App\Models\AuditSynopticCorrection;
use App\Models\WorkSite;
use App\Planning\NavigationPoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\DesignOfficeWorkflowFixtures;
use Tests\TestCase;

/**
 * Versioned documents, box positions and synoptic corrections of the design office workflow (spec 13 §39, §41):
 * map files add « map origin » positions by box name without overwriting, a position becomes official only through the
 * design office or a supervisor and « J'y vais » leads there, technicians propose corrections (positions, synoptic)
 * that the design office approves, includes in a consolidated synoptic or rejects. With the authorization of each
 * action (404 out of scope, 403 forbidden).
 */
class DocumentsAndPositionsTest extends TestCase
{
    use DesignOfficeWorkflowFixtures;
    use RefreshDatabase;

    private AuditBox $first;

    private AuditBox $second;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->setUpWorkflow();
        $this->first = $this->box(attributes: ['name' => 'BPE-0101']);
        $this->second = $this->box(attributes: ['name' => 'BPE-0102']);
    }

    /**
     * @param  array<string, array{float, float}>  $points
     */
    private function kml(array $points, string $name = 'boxes.kml'): UploadedFile
    {
        $placemarks = collect($points)->map(fn (array $point, string $box) => "<Placemark><name>{$box}</name><Point><coordinates>{$point[1]},{$point[0]},0</coordinates></Point></Placemark>")->implode('');

        return UploadedFile::fake()->createWithContent($name, '<?xml version="1.0" encoding="UTF-8"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document>'.$placemarks.'</Document></kml>');
    }

    /**
     * @param  callable(): mixed  $action
     */
    private function refusal(callable $action, int $status, string $reason): void
    {
        try {
            $action();
        } catch (WorkflowException $exception) {
            $this->assertSame([$status, $reason], [$exception->status, $exception->reason]);

            return;
        }

        $this->fail("Expected a refusal {$status} {$reason}.");
    }

    public function test_a_map_file_adds_map_origin_positions_by_name_and_lists_the_unknown_points(): void
    {
        $versions = app(DocumentVersions::class);
        $result = $versions->publish($this->reviewer, $this->audit, AuditDocumentKind::Map, $this->kml(['BPE-0101' => [45.1, 1.5], 'BPE-0999' => [45.2, 1.6]]));

        $this->assertFalse($result['duplicate']);
        $this->assertSame(1, $result['version']->version_number);
        $this->assertSame(['placemarks' => 2, 'matched' => 1, 'unmatched' => ['BPE-0999']], $result['version']->summary);
        $origin = AuditBoxPosition::query()->where('audit_box_id', $this->first->id)->sole();
        $this->assertSame(AuditPositionSource::MapOrigin, $origin->source);
        $this->assertSame('45.1000000', $origin->latitude);

        $this->assertTrue($versions->publish($this->reviewer, $this->audit, AuditDocumentKind::Map, $this->kml(['BPE-0101' => [45.1, 1.5], 'BPE-0999' => [45.2, 1.6]]))['duplicate']);

        $second = $versions->publish($this->supervisor, $this->audit, AuditDocumentKind::Map, $this->kml(['BPE-0101' => [45.1, 1.5], 'BPE-0102' => [45.3, 1.7]], 'v2.kml'))['version'];

        $this->assertSame(2, $second->version_number);
        $this->assertFalse(AuditDocumentVersion::query()->where('version_number', 1)->sole()->is_current);
        $this->assertTrue($second->is_current);
        $this->assertSame(1, AuditBoxPosition::query()->where('audit_box_id', $this->first->id)->count(), 'An unchanged position is not added again.');
        $this->assertSame(1, AuditBoxPosition::query()->where('audit_box_id', $this->second->id)->count());

        $this->refusal(fn () => $versions->publish($this->reviewer, $this->audit, AuditDocumentKind::Map, UploadedFile::fake()->createWithContent('empty.kml', '<kml xmlns="http://www.opengis.net/kml/2.2"></kml>')), 422, 'map_without_points');
    }

    public function test_the_synoptic_and_map_given_at_configuration_become_versions(): void
    {
        $result = app(ConfigureAudit::class)->handle($this->supervisor, $this->audit, [], [], ['boxes' => ['BPE-0102' => [45.3, 1.7]], 'cabinet' => null], [
            'synoptic' => UploadedFile::fake()->createWithContent('synoptic.txt', "BPE-0101\nBPE-0102\n"),
            'map' => $this->kml(['BPE-0102' => [45.3, 1.7]]),
        ]);

        $this->assertSame(1, $result['located']);
        $this->assertSame(1, AuditDocumentVersion::query()->where('kind', AuditDocumentKind::Synoptic->value)->sole()->version_number);
        $map = AuditDocumentVersion::query()->where('kind', AuditDocumentKind::Map->value)->with('file')->sole();
        $this->assertSame(2, $map->file->reference_count, 'The audit and its version each hold the file.');
        $this->assertSame(AuditPositionSource::MapOrigin, AuditBoxPosition::query()->where('audit_box_id', $this->second->id)->sole()->source);
    }

    public function test_the_design_office_publishes_a_consolidated_synoptic_including_proposed_corrections(): void
    {
        $corrections = app(SynopticCorrections::class);
        $included = $corrections->propose($this->technician, $this->audit, $this->first, 'op_a', 'The cable goes to BPE-0102.')['correction'];
        $other = $corrections->propose($this->partner, $this->audit, null, 'op_b', 'The cabinet has two drawers.')['correction'];

        Livewire::actingAs($this->reviewer)->test(DocumentsPanel::class, ['auditId' => $this->audit->id])
            ->set('kind', 'synoptic')
            ->set('document', UploadedFile::fake()->createWithContent('synoptic.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n"))
            ->set('included', [$included->id])
            ->call('publish')
            ->assertHasNoErrors();

        $version = AuditDocumentVersion::query()->sole();
        $this->assertSame(['corrections' => [$included->id]], $version->summary);
        $this->assertSame(AuditSynopticCorrectionState::Included, $included->refresh()->state);
        $this->assertSame($version->id, $included->audit_document_version_id);
        $this->assertSame(AuditSynopticCorrectionState::Open, $other->refresh()->state);

        Livewire::actingAs($this->reviewer)->test(DocumentsPanel::class, ['auditId' => $this->audit->id])
            ->call('startRejecting', $other->id)->set('rejectionReason', 'Already on the plan.')->call('rejectCorrection')->assertHasNoErrors();
        $this->assertSame(AuditSynopticCorrectionState::Rejected, $other->refresh()->state);
    }

    public function test_a_versioned_document_has_its_own_file_types(): void
    {
        Livewire::actingAs($this->reviewer)->test(DocumentsPanel::class, ['auditId' => $this->audit->id])
            ->set('kind', 'synoptic')
            ->set('document', UploadedFile::fake()->createWithContent('boxes.kml', '<kml></kml>'))
            ->call('publish')
            ->assertHasErrors('document');

        $this->assertSame(0, AuditDocumentVersion::query()->count());
    }

    public function test_documents_are_published_by_the_design_office_and_supervisors_and_read_by_the_team(): void
    {
        $versions = app(DocumentVersions::class);
        $old = $versions->publish($this->reviewer, $this->audit, AuditDocumentKind::Map, $this->kml(['BPE-0101' => [45.1, 1.5]]))['version'];
        $current = $versions->publish($this->reviewer, $this->audit, AuditDocumentKind::Map, $this->kml(['BPE-0101' => [45.2, 1.5]], 'v2.kml'))['version'];

        Livewire::actingAs($this->technician)->test(DocumentsPanel::class, ['auditId' => $this->audit->id])->assertForbidden();

        $this->actingAs($this->technician)->getJson(route('design-office.audits.documents', $this->audit))
            ->assertOk()->assertJsonPath('current.map.number', 2)->assertJsonPath('current.synoptic', null)->assertJsonPath('versions', null);
        $this->actingAs($this->technician)->get(route('design-office.documents.download', $current))->assertOk();
        $this->actingAs($this->technician)->get(route('design-office.documents.download', $old))->assertNotFound();
        $this->actingAs($this->reviewer)->get(route('design-office.documents.download', $old))->assertOk();
        $this->actingAs($this->reviewer)->getJson(route('design-office.audits.documents', $this->audit))->assertOk()->assertJsonCount(2, 'versions');

        $stranger = $this->person(Role::Technician);
        $this->actingAs($stranger)->getJson(route('design-office.audits.documents', $this->audit))->assertNotFound();
        $this->actingAs($stranger)->get(route('design-office.documents.download', $current))->assertNotFound();

        $this->switchWorkflowModule(false);
        $this->actingAs($this->reviewer)->getJson(route('design-office.audits.documents', $this->audit))->assertNotFound();
    }

    public function test_nothing_is_published_once_the_pm_is_closed(): void
    {
        $this->audit->forceFill(['closed_at' => now(), 'closed_by' => $this->supervisor->id])->save();

        $this->refusal(fn () => app(DocumentVersions::class)->publish($this->reviewer, $this->audit, AuditDocumentKind::Map, $this->kml(['BPE-0101' => [45.1, 1.5]])), 409, 'audit_closed');
        $this->refusal(fn () => app(SynopticCorrections::class)->propose($this->technician, $this->audit, null, 'op_c', 'Some correction.'), 409, 'audit_closed');
        $this->refusal(fn () => app(BoxPositions::class)->propose($this->technician, $this->first, 45.1, 1.5), 409, 'audit_closed');
    }

    public function test_a_technician_proposes_a_position_the_design_office_makes_it_official_and_j_y_vais_leads_there(): void
    {
        $url = route('design-office.boxes.positions.propose', $this->first);

        $id = $this->actingAs($this->technician)->postJson($url, ['latitude' => 45.12345, 'longitude' => 1.54321, 'accuracy' => 5])
            ->assertCreated()->assertJsonPath('position.state', 'pending')->json('position.id');
        $this->actingAs($this->technician)->postJson($url, ['latitude' => 45.12345, 'longitude' => 1.54321])->assertOk()->assertJsonPath('duplicate', true);
        $this->actingAs($this->technician)->postJson($url, ['latitude' => 95, 'longitude' => 1.5])->assertUnprocessable();

        Livewire::actingAs($this->technician)->test(BoxPositionsPanel::class, ['boxId' => $this->first->id])->call('approve', $id)->assertForbidden();
        Livewire::actingAs($this->reviewer)->test(BoxPositionsPanel::class, ['boxId' => $this->first->id])
            ->call('approve', $id)->assertHasNoErrors()
            ->call('openNavigation')->assertSee('waze.com/ul?ll=45.12345,1.54321', false);

        $official = $this->first->refresh()->officialPosition;
        $this->assertSame(AuditPositionSource::Official, $official?->source);
        $this->assertSame($this->reviewer->id, $official->approved_by);
        $this->assertNotNull(AuditBoxPosition::query()->findOrFail($id)->approved_at);

        $this->actingAs($this->technician)->getJson(route('design-office.boxes.positions', $this->first))
            ->assertOk()->assertJsonPath('official.latitude', 45.12345)->assertJsonPath('proposals.0.state', 'approved')
            ->assertJsonPath('navigation.maps', 'https://www.google.com/maps/dir/?api=1&destination=45.12345,1.54321');

        $snapshot = app(AuditSnapshot::class)->for($this->audit->refresh(), $this->technician);
        $this->assertSame(['latitude' => 45.12345, 'longitude' => 1.54321], collect($snapshot['boxes'])->firstWhere('id', $this->first->id)['official']);

        Livewire::actingAs($this->reviewer)->test(BoxPositionsPanel::class, ['boxId' => $this->first->id])->call('approve', $id)->assertHasErrors('positions');
    }

    public function test_the_planning_directions_use_the_official_position_of_a_design_office_box(): void
    {
        $this->first->update(['latitude' => '44.0000000', 'longitude' => '1.0000000']);
        AuditBoxPosition::query()->create(['audit_box_id' => $this->first->id, 'source' => AuditPositionSource::Official, 'latitude' => '45.5000000', 'longitude' => '1.5000000', 'approved_by' => $this->reviewer->id, 'approved_at' => now()]);
        $site = WorkSite::query()->where('audit_id', $this->audit->id)->firstOrFail();

        $points = app(NavigationPoints::class)->for($site, ['BPE-0101', 'BPE-0102']);

        $this->assertSame(['latitude' => 45.5, 'longitude' => 1.5], $points['boxes']['BPE-0101']);
        $this->assertArrayNotHasKey('BPE-0102', $points['boxes']);
    }

    public function test_a_position_confirmed_on_site_is_kept_as_the_field_position_and_can_be_made_official(): void
    {
        $this->actingAs($this->technician);
        app(AuditOperations::class)->apply($this->technician, $this->audit, 'op_pos', ['op' => 'box_pos', 'box' => 'BPE-0101', 'latitude' => 45.11, 'longitude' => 1.51, 'accuracy' => 7]);

        $field = AuditBoxPosition::query()->where('audit_box_id', $this->first->id)->sole();
        $this->assertSame([AuditPositionSource::Field, 7, $this->technician->id], [$field->source, $field->accuracy, $field->recorded_by]);

        app(BoxPositions::class)->approve($this->supervisor, $field);
        $this->assertSame('45.1100000', $this->first->refresh()->officialPosition?->latitude);
        $this->refusal(fn () => app(BoxPositions::class)->approve($this->supervisor, $this->first->officialPosition), 409, 'position_already_official');
    }

    public function test_a_proposal_is_rejected_with_a_reason(): void
    {
        $proposal = app(BoxPositions::class)->propose($this->technician, $this->first, 45.1, 1.5)['position'];

        Livewire::actingAs($this->reviewer)->test(BoxPositionsPanel::class, ['boxId' => $this->first->id])
            ->call('startRejecting', $proposal->id)->set('rejectionReason', '')->call('reject')->assertHasErrors('rejectionReason')
            ->set('rejectionReason', 'The box is across the road.')->call('reject')->assertHasNoErrors();

        $proposal->refresh();
        $this->assertSame('The box is across the road.', $proposal->rejection_reason);
        $this->assertNull($this->first->refresh()->officialPosition);
        $this->refusal(fn () => app(BoxPositions::class)->approve($this->reviewer, $proposal), 409, 'position_already_decided');
    }

    public function test_position_actions_are_authorized_on_the_server(): void
    {
        $stranger = $this->person(Role::Technician);

        $this->actingAs($this->reviewer)->postJson(route('design-office.boxes.positions.propose', $this->first), ['latitude' => 45, 'longitude' => 1])->assertForbidden();
        $this->actingAs($stranger)->postJson(route('design-office.boxes.positions.propose', $this->first), ['latitude' => 45, 'longitude' => 1])->assertNotFound();
        $this->actingAs($stranger)->getJson(route('design-office.boxes.positions', $this->first))->assertNotFound();
        $this->actingAs($this->reviewer)->getJson(route('design-office.boxes.positions', $this->first))->assertOk();
        Livewire::actingAs($stranger)->test(BoxPositionsPanel::class, ['boxId' => $this->first->id])->assertNotFound();

        $other = $this->box($this->plannedAudit([$stranger]));
        $proposal = app(BoxPositions::class)->propose($stranger, $other, 45, 1)['position'];
        Livewire::actingAs($this->reviewer)->test(BoxPositionsPanel::class, ['boxId' => $this->first->id])->call('approve', $proposal->id)->assertNotFound();
    }

    public function test_technicians_propose_synoptic_corrections_from_the_phone_and_follow_their_own(): void
    {
        $url = route('design-office.audits.synoptic-corrections.store', $this->audit);
        $photo = $this->photo($this->first, AuditFolder::Before);

        $this->actingAs($this->technician)->postJson($url, ['operation_id' => 'op_1', 'text' => 'BPE-0101 is fed by BPE-0102.', 'box' => 'bpe-0101', 'photo' => $photo->id])
            ->assertCreated()->assertJsonPath('correction.box', 'BPE-0101')->assertJsonPath('correction.state', 'open');
        $this->actingAs($this->technician)->postJson($url, ['operation_id' => 'op_1', 'text' => 'BPE-0101 is fed by BPE-0102.'])->assertOk()->assertJsonPath('duplicate', true);
        $this->actingAs($this->partner)->postJson($url, ['operation_id' => 'op_2', 'text' => 'Two drawers in the cabinet.'])->assertCreated();
        $this->actingAs($this->partner)->postJson($url, ['operation_id' => 'op_3', 'text' => 'abc'])->assertUnprocessable()->assertJsonPath('reason', 'correction_text_required');
        $this->actingAs($this->partner)->postJson($url, ['operation_id' => 'op_4', 'text' => 'Unknown box.', 'box' => 'BPE-9999'])->assertNotFound();

        $this->assertSame(2, AuditSynopticCorrection::query()->count());
        $this->actingAs($this->technician)->getJson(route('design-office.audits.synoptic-corrections', $this->audit))->assertOk()->assertJsonCount(1, 'corrections');
        $this->actingAs($this->reviewer)->getJson(route('design-office.audits.synoptic-corrections', $this->audit))->assertOk()->assertJsonCount(2, 'corrections');

        $this->actingAs($this->reviewer)->postJson($url, ['operation_id' => 'op_5', 'text' => 'Not a technician.'])->assertForbidden();
        $this->actingAs($this->person(Role::Technician))->postJson($url, ['operation_id' => 'op_6', 'text' => 'Not planned here.'])->assertNotFound();

        $correction = AuditSynopticCorrection::query()->firstOrFail();
        Livewire::actingAs($this->reviewer)->test(DocumentsPanel::class, ['auditId' => $this->audit->id])
            ->call('startRejecting', $correction->id)->set('rejectionReason', 'ok')->call('rejectCorrection')->assertHasErrors('rejectionReason');
    }

    public function test_the_team_is_not_required_to_propose_but_the_box_must_be_in_service(): void
    {
        app(BoxTeam::class)->claim($this->technician, $this->first);
        $this->first->update(['removed_at' => now()]);

        $this->refusal(fn () => app(BoxPositions::class)->propose($this->partner, $this->first, 45.1, 1.5), 409, 'box_removed');
    }
}
