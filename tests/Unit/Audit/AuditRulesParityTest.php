<?php

namespace Tests\Unit\Audit;

use App\Audit\BoxFolders;
use App\Audit\BoxProgress;
use App\Enums\AuditBoxKind;
use App\Enums\AuditBoxStatus;
use App\Enums\AuditFibreType;
use App\Enums\AuditMode;
use App\Enums\AuditSupport;
use App\Models\AuditBox;
use App\Models\AuditCable;
use App\Models\AuditPhoto;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The phone computes folders and progress with its own copy of the rules (it has no network); this test runs both
 * on the same few hundred boxes and fails as soon as they differ.
 */
class AuditRulesParityTest extends TestCase
{
    public function test_the_phone_and_the_server_agree_on_folders_and_progress(): void
    {
        mt_srand(20261029);
        $folders = ['surroundings', 'facade_surroundings', 'interior', 'closed', 'open', 'labels', 'entries', 'storage', 'placed', 'cassettes', 'tco', 'lashing', 'recheck', 'pole', 'before', 'fixing'];
        $sections = ['whole', 'head', 'label', 'conduit', 'before', 'after'];
        $cases = [];

        for ($i = 0; $i < 600; $i++) {
            $photos = [];

            foreach ($folders as $folder) {
                if (mt_rand(0, 2) === 0) {
                    $photos[] = ['folder' => $folder, 'subfolder' => $folder === 'pole' ? $sections[mt_rand(0, 5)] : ($folder === 'labels' || $folder === 'lashing' ? ['', 'CAB1X', 'CAB2X'][mt_rand(0, 2)] : '')];
                }
            }

            if (mt_rand(0, 1) === 1) {
                foreach (array_slice($sections, 0, mt_rand(2, 6)) as $section) {
                    $photos[] = ['folder' => 'pole', 'subfolder' => $section];
                }
            }

            $cables = [];

            foreach (['CAB1X', 'CAB2X'] as $code) {
                if (mt_rand(0, 1) === 1) {
                    $cables[] = ['code' => $code, 'has_no_label' => mt_rand(0, 2) === 0, 'capacity' => mt_rand(0, 3) === 0 ? null : '12FO', 'fibre_type' => mt_rand(0, 3) === 0 ? null : 'g657'];
                }
            }

            $cases[] = ['mode' => mt_rand(0, 1) === 1 ? 'optical' : 'full', 'box' => [
                'kind' => ['bpe', 'pbo', 'jd'][mt_rand(0, 2)], 'support' => [null, 'chamber', 'pole', 'facade'][mt_rand(0, 3)],
                'fibre_type' => mt_rand(0, 1) === 1 ? 'g657' : null, 'status' => [null, 'ok', 'anomaly', 'not_installed', 'partial'][mt_rand(0, 4)],
                'returned' => mt_rand(0, 4) === 0 ? ['comment' => 'again'] : null, 'confirmed' => mt_rand(0, 1) === 1 ? ['latitude' => 43.9, 'longitude' => 2.1] : null,
                'photos' => $photos, 'cables' => $cables,
            ]];
        }

        $process = new Process(['node', base_path('tests/Support/audit-rules-parity.mjs')], base_path(), null, json_encode($cases));
        $process->mustRun();
        $phone = json_decode($process->getOutput(), true);

        $this->assertCount(count($cases), $phone);

        foreach ($cases as $index => $case) {
            $box = $this->model($case['box']);
            $mode = AuditMode::from($case['mode']);
            $hasTco = collect($case['box']['photos'])->contains(fn (array $photo) => $photo['folder'] === 'tco');

            $server = [
                'percent' => app(BoxProgress::class)->percent($box, $mode),
                'items' => app(BoxProgress::class)->items($box, $mode),
                'folders' => array_map(fn ($folder) => $folder->value, app(BoxFolders::class)->for($box, $mode, $hasTco)),
                'final' => $box->isFinal(),
            ];

            $this->assertSame($server, $phone[$index], 'Case '.$index.': '.json_encode($case));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function model(array $data): AuditBox
    {
        $box = new AuditBox([
            'kind' => AuditBoxKind::from($data['kind']), 'support' => $data['support'] === null ? null : AuditSupport::from($data['support']),
            'fibre_type' => $data['fibre_type'] === null ? null : AuditFibreType::from($data['fibre_type']),
            'status' => $data['status'] === null ? null : AuditBoxStatus::from($data['status']),
            'returned_at' => $data['returned'] === null ? null : Carbon::now(), 'confirmed_at' => $data['confirmed'] === null ? null : Carbon::now(),
        ]);
        $box->setRelation('photos', collect(array_map(fn (array $photo) => new AuditPhoto($photo), $data['photos'])));
        $box->setRelation('cables', collect(array_map(fn (array $cable) => new AuditCable([
            'code' => $cable['code'], 'has_no_label' => $cable['has_no_label'], 'capacity' => $cable['capacity'], 'fibre_type' => $cable['fibre_type'] === null ? null : AuditFibreType::from($cable['fibre_type']),
        ]), $data['cables'])));

        return $box;
    }
}
