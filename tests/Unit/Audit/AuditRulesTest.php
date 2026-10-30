<?php

namespace Tests\Unit\Audit;

use App\Audit\BoxFolders;
use App\Audit\BoxName;
use App\Audit\BoxProgress;
use App\Enums\AuditBoxKind;
use App\Enums\AuditBoxStatus;
use App\Enums\AuditFibreType;
use App\Enums\AuditFolder;
use App\Enums\AuditMode;
use App\Enums\AuditSupport;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditCable;
use App\Models\AuditPhoto;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditRulesTest extends TestCase
{
    /**
     * @param  list<array{string, string}>  $photos  [folder, subfolder]
     * @param  list<AuditCable>  $cables
     * @param  array<string, mixed>  $attributes
     */
    private function box(AuditBoxKind $kind, ?AuditSupport $support, array $photos = [], array $cables = [], array $attributes = []): AuditBox
    {
        $box = new AuditBox(['name' => 'BOX', 'kind' => $kind, 'support' => $support] + $attributes);
        $box->setRelation('photos', collect(array_map(fn (array $photo) => new AuditPhoto(['folder' => $photo[0], 'subfolder' => $photo[1] ?? '']), $photos)));
        $box->setRelation('cables', collect($cables));

        return $box;
    }

    /**
     * @param  list<AuditFolder>  $folders
     * @return list<array{string, string}>
     */
    private function photosOf(array $folders): array
    {
        return array_map(fn (AuditFolder $folder) => [$folder->value, ''], $folders);
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function names(): array
    {
        return [
            'bpe' => ['BPE-0001', 'bpe'], 'bpe operator' => ['BPE-0001-FT', 'bpe'], 'bpe letter' => ['BPE-0241-B', 'bpe'], 'bpe small' => ['BPE-006-2', 'bpe'],
            'bpe joint' => ['BPE-0100-JD', 'jd'], 'bpe joint operator' => ['BPE-0029-JD-FT', 'jd'], 'joint bpe' => ['JD1-BPE-0003', 'jd'], 'joint bare' => ['JD-BPE-0005', 'jd'],
            'pb' => ['106-PB0001', 'pbo'], 'pb joint' => ['106-PB73JD', 'jd'], 'pb joint prefix' => ['JD1-PB0024', 'jd'], 'pbo joint' => ['JD-PBO-59', 'jd'], 'lower case' => ['bpe-0001', 'bpe'],
            'unknown' => ['SOMETHING', null], 'empty' => ['', null], 'trailing text' => ['BPE-0001 extra', null],
        ];
    }

    #[DataProvider('names')]
    public function test_names_are_classified_by_one_grammar(string $name, ?string $kind): void
    {
        config(['audit.box_suffixes' => ['FT']]);

        $this->assertSame($kind, BoxName::classify($name)?->value);
    }

    public function test_operator_suffixes_come_from_the_configuration(): void
    {
        config(['audit.box_suffixes' => []]);
        $this->assertNull(BoxName::classify('BPE-0001-FT'));
        $this->assertSame(AuditBoxKind::Bpe, BoxName::classify('BPE-0001'));

        config(['audit.box_suffixes' => ['ft', 'NET']]);
        $this->assertSame(AuditBoxKind::Bpe, BoxName::classify('BPE-0012-NET'));
        $this->assertSame(AuditBoxKind::Bpe, BoxName::classify('BPE-0001-FT'));
    }

    public function test_names_and_cabinet_keys_are_normalised(): void
    {
        $this->assertSame('BPE-0001 FT', BoxName::normalise("  bpe-0001 \t ft "));
        $this->assertSame('PM153_A-1', BoxName::cabinetKey('pm 153_a-1!'));
        $this->assertSame(30, strlen(BoxName::cabinetKey(str_repeat('A', 50))));
    }

    public function test_the_full_mode_folders_depend_on_support_and_kind(): void
    {
        $folders = fn (AuditBox $box) => array_map(fn (AuditFolder $folder) => $folder->value, app(BoxFolders::class)->for($box, AuditMode::Full));

        $this->assertSame(['surroundings', 'interior', 'closed', 'open', 'labels', 'entries', 'storage', 'placed', 'cassettes'], $folders($this->box(AuditBoxKind::Bpe, AuditSupport::Chamber)));
        $this->assertSame($folders($this->box(AuditBoxKind::Bpe, AuditSupport::Chamber)), $folders($this->box(AuditBoxKind::Bpe, null)));
        $this->assertSame(['closed', 'open', 'labels', 'entries', 'storage', 'fixing', 'cassettes', 'tco', 'pole'], $folders($this->box(AuditBoxKind::Pbo, AuditSupport::Pole)));
        $this->assertSame(['facade_surroundings', 'closed', 'open', 'labels', 'entries', 'storage', 'fixing', 'cassettes', 'tco'], $folders($this->box(AuditBoxKind::Pbo, AuditSupport::Facade)));
        $this->assertSame(['closed', 'open', 'labels', 'entries', 'storage', 'fixing', 'cassettes'], $folders($this->box(AuditBoxKind::Joint, AuditSupport::Facade)));
    }

    public function test_the_optical_mode_folders_depend_on_support_and_kind(): void
    {
        $folders = fn (AuditBox $box, bool $tco = false) => array_map(fn (AuditFolder $folder) => $folder->value, app(BoxFolders::class)->for($box, AuditMode::Optical, $tco));

        $this->assertSame(['surroundings', 'interior', 'closed', 'open', 'cassettes', 'labels', 'lashing'], $folders($this->box(AuditBoxKind::Bpe, AuditSupport::Chamber)));
        $this->assertSame(['pole', 'closed', 'open', 'cassettes', 'tco', 'labels', 'lashing'], $folders($this->box(AuditBoxKind::Pbo, AuditSupport::Pole)));
        $this->assertSame(['facade_surroundings', 'closed', 'open', 'cassettes', 'tco', 'labels', 'lashing'], $folders($this->box(AuditBoxKind::Pbo, AuditSupport::Facade)));
        $this->assertSame(['closed', 'open', 'cassettes', 'labels', 'lashing'], $folders($this->box(AuditBoxKind::Bpe, null)));
        $this->assertContains('tco', $folders($this->box(AuditBoxKind::Bpe, AuditSupport::Pole), true));
    }

    public function test_full_mode_progress_counts_folders_cable_and_status(): void
    {
        $progress = app(BoxProgress::class);
        $done = $this->photosOf([AuditFolder::Surroundings, AuditFolder::Interior, AuditFolder::Closed, AuditFolder::Open, AuditFolder::Labels, AuditFolder::Entries, AuditFolder::Storage, AuditFolder::Placed, AuditFolder::Cassettes]);

        $empty = $this->box(AuditBoxKind::Bpe, AuditSupport::Chamber);
        $this->assertSame(0, $progress->percent($empty, AuditMode::Full));
        $this->assertCount(11, $progress->items($empty, AuditMode::Full));

        $photosOnly = $this->box(AuditBoxKind::Bpe, AuditSupport::Chamber, $done);
        $this->assertSame(82, $progress->percent($photosOnly, AuditMode::Full));

        $complete = $this->box(AuditBoxKind::Bpe, AuditSupport::Chamber, $done, [], ['fibre_type' => AuditFibreType::G657, 'status' => AuditBoxStatus::Ok]);
        $this->assertSame(100, $progress->percent($complete, AuditMode::Full));

        $returned = $this->box(AuditBoxKind::Bpe, AuditSupport::Chamber, $done, [], ['fibre_type' => AuditFibreType::G657, 'status' => AuditBoxStatus::Ok, 'returned_at' => Carbon::now()]);
        $this->assertSame(91, $progress->percent($returned, AuditMode::Full));
        $this->assertFalse($returned->isFinal());
        $this->assertTrue($complete->isFinal());
    }

    public function test_a_box_not_installed_counts_as_done_unless_it_was_sent_back(): void
    {
        $progress = app(BoxProgress::class);
        $box = $this->box(AuditBoxKind::Bpe, AuditSupport::Chamber, [], [], ['status' => AuditBoxStatus::NotInstalled]);

        $this->assertSame(100, $progress->percent($box, AuditMode::Full));
        $this->assertTrue($box->isFinal());

        $box->returned_at = Carbon::now();
        $this->assertSame(0, $progress->percent($box, AuditMode::Optical));
    }

    public function test_optical_progress_needs_complete_cables_and_the_confirmed_position(): void
    {
        $progress = app(BoxProgress::class);
        $complete = new AuditCable(['code' => 'CAB1X', 'capacity' => '12FO', 'fibre_type' => AuditFibreType::G657, 'has_no_label' => false]);
        $noLabel = new AuditCable(['code' => 'CAB2X', 'capacity' => '6FO', 'fibre_type' => AuditFibreType::G652, 'has_no_label' => true]);
        $unlabelled = new AuditCable(['code' => 'CAB3X', 'capacity' => '6FO', 'fibre_type' => AuditFibreType::G652, 'has_no_label' => false]);
        $photos = $this->photosOf([AuditFolder::Surroundings, AuditFolder::Interior, AuditFolder::Closed, AuditFolder::Open]);

        $box = $this->box(AuditBoxKind::Bpe, AuditSupport::Chamber, [...$photos, ['labels', 'CAB1X']], [$complete, $noLabel]);
        $this->assertSame(['surroundings', 'interior', 'closed', 'open', 'cable', 'position', 'status'], array_column($progress->items($box, AuditMode::Optical), 'key'));
        $this->assertTrue(collect($progress->items($box, AuditMode::Optical))->firstWhere('key', 'cable')['done']);

        $incomplete = $this->box(AuditBoxKind::Bpe, AuditSupport::Chamber, [...$photos, ['labels', 'CAB1X']], [$complete, $unlabelled]);
        $this->assertFalse(collect($progress->items($incomplete, AuditMode::Optical))->firstWhere('key', 'cable')['done']);
        $this->assertFalse(collect($progress->items($this->box(AuditBoxKind::Bpe, AuditSupport::Chamber), AuditMode::Optical))->firstWhere('key', 'cable')['done']);

        $box->confirmed_at = Carbon::now();
        $box->status = AuditBoxStatus::Anomaly;
        $this->assertSame(100, $progress->percent($box, AuditMode::Optical));
    }

    public function test_the_pole_folder_needs_the_three_sections_or_the_older_before_and_after(): void
    {
        $progress = app(BoxProgress::class);
        $pole = fn (array $subs) => $this->box(AuditBoxKind::Bpe, AuditSupport::Pole, array_map(fn (string $sub) => ['pole', $sub], $subs));
        $done = fn (AuditBox $box) => collect($progress->items($box, AuditMode::Full))->firstWhere('key', 'pole')['done'];

        $this->assertTrue($done($pole(['whole', 'head', 'label'])));
        $this->assertFalse($done($pole(['whole', 'head'])));
        $this->assertFalse($done($pole(['whole', 'head', 'conduit'])));
        $this->assertTrue($done($pole(['before', 'after'])));
        $this->assertFalse($done($pole(['before'])));
    }

    public function test_the_audit_percent_averages_the_boxes_still_in_the_audit(): void
    {
        $progress = app(BoxProgress::class);
        $audit = new Audit(['mode' => AuditMode::Full]);
        $audit->setRelation('boxes', collect());
        $this->assertSame(0, $progress->auditPercent($audit));

        $finished = $this->box(AuditBoxKind::Bpe, AuditSupport::Chamber, [], [], ['status' => AuditBoxStatus::NotInstalled]);
        $empty = $this->box(AuditBoxKind::Bpe, AuditSupport::Chamber);
        $removed = $this->box(AuditBoxKind::Bpe, AuditSupport::Chamber, [], [], ['status' => AuditBoxStatus::NotInstalled, 'removed_at' => Carbon::now()]);
        $audit->setRelation('boxes', collect([$finished, $empty, $removed]));

        $this->assertSame(50, $progress->auditPercent($audit));
        $this->assertSame(AuditMode::Full, (new Audit)->effectiveMode());
    }
}
