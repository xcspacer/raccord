<?php

namespace Tests\Unit\Audit;

use App\Audit\BoxName;
use App\Audit\Import\KmlReader;
use App\Audit\Import\SynopticListParser;
use App\Enums\AuditBoxKind;
use App\Enums\AuditSupport;
use Tests\TestCase;
use ZipArchive;

class AuditImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['audit.box_suffixes' => ['FT', 'NET'], 'audit.synoptic.stop_prefixes' => ['NET', 'Chambres travers']]);
    }

    public function test_different_names_of_one_box_share_a_canonical_key(): void
    {
        $this->assertSame(BoxName::canonicalKey('PBO-SRO-BPI-8109935-0073'), BoxName::canonicalKey('900-PB0073'));
        $this->assertSame(BoxName::canonicalKey('900-PB0073'), BoxName::canonicalKey('PB73.xlsx'));
        $this->assertNotSame(BoxName::canonicalKey('900-PB0073'), BoxName::canonicalKey('900-PB73JD'));
        $this->assertSame(BoxName::canonicalKey('BPE-0001-FT'), BoxName::canonicalKey('bpe-1-81201-ft.xlsx'));
        $this->assertNotSame(BoxName::canonicalKey('BPE-0001-FT'), BoxName::canonicalKey('BPE-0001-NET'));
        $this->assertSame(BoxName::canonicalKey('JD-PBO-59'), BoxName::canonicalKey('JD-PBO-0059'));
        $this->assertSame('', BoxName::canonicalKey('Notes'));
    }

    public function test_the_box_of_a_plan_file_is_found_from_its_name(): void
    {
        $this->assertSame('900-PB0073', BoxName::fromPlanFile('PBO-SRO-BPI-8109935-0073.xlsx', 'PM900'));
        $this->assertSame('900-PB73JD', BoxName::fromPlanFile('PBO-SRO-BPI-8109935-73JD.xlsx', 'PM900'));
        $this->assertSame('JD1-PB0024', BoxName::fromPlanFile('JD1-PBO-SRO-BPI-8109935-024.xlsx', 'PM900'));
        $this->assertSame('BPE-0001-FT', BoxName::fromPlanFile('BPE-0001-81201-FT.xlsx', 'PM900'));
        $this->assertSame('BPE-0002-FT', BoxName::fromPlanFile('bpe-0002-ft.xlsx', 'PM900'));
    }

    public function test_the_synoptic_text_gives_boxes_supports_types_and_addresses(): void
    {
        $text = <<<'TEXT'
        BPE-0001-FT [CHAMBRE FT : L2T 12]
        BPE 144 FO
        120 FO câble
        900-PB0001 [POTEAU : 45]
        PBO taille 1 NG
        12 rue des Tilleuls
        81000 Demo-Ville
        T01 F01
        Chambres travers
        JD1-BPE-0003-FT [FAÇADE : -]
        NET Something
        BPE-0001-FT
        Unrelated heading
        TEXT;

        $boxes = (new SynopticListParser)->parse($text);

        $this->assertSame(['BPE-0001-FT', '900-PB0001', 'JD1-BPE-0003-FT'], array_column($boxes, 'name'));
        $this->assertSame([AuditBoxKind::Bpe, AuditBoxKind::Pbo, AuditBoxKind::Joint], array_column($boxes, 'kind'));
        $this->assertSame([AuditSupport::Chamber, AuditSupport::Pole, AuditSupport::Facade], array_column($boxes, 'support'));
        $this->assertSame(['L2T 12', '45', ''], array_column($boxes, 'support_number'));
        $this->assertSame('BPE 144 FO', $boxes[0]['type']);
        $this->assertSame('', $boxes[0]['address']);
        $this->assertSame('PBO taille 1 NG', $boxes[1]['type']);
        $this->assertSame('12 rue des Tilleuls, 81000 Demo-Ville', $boxes[1]['address']);
    }

    public function test_a_later_line_with_a_support_replaces_a_first_one_without(): void
    {
        $boxes = (new SynopticListParser)->parse("BPE-0005\nBPE-0005 [POTEAU : 7]\nBPE-0006 [POTEAU : 1]\nBPE-0006 [CHAMBRE : 2]");

        $this->assertCount(2, $boxes);
        $this->assertSame(AuditSupport::Pole, $boxes[0]['support']);
        $this->assertSame('7', $boxes[0]['support_number']);
        $this->assertSame(AuditSupport::Pole, $boxes[1]['support']);
    }

    public function test_an_unreadable_synoptic_gives_no_boxes(): void
    {
        $this->assertSame([], (new SynopticListParser)->parse("Scanned document\n\n  \nnothing here"));
    }

    private function kml(): string
    {
        return <<<'KML'
        <?xml version="1.0" encoding="UTF-8"?>
        <kml xmlns="http://www.opengis.net/kml/2.2"><Document><name>Network</name>
          <Folder><name>POSITION_PM</name><Placemark><name>Cabinet</name><Point><coordinates>2.1400,43.9100,0</coordinates></Point></Placemark></Folder>
          <Folder><name>BOITES</name>
            <Placemark><name>BPE-0001-FT</name><Point><coordinates>2.1480,43.9300,0</coordinates></Point></Placemark>
            <Placemark><name>900-PB0002</name><Point><coordinates>2.1500,43.9310</coordinates></Point></Placemark>
            <Placemark><name>Cable</name><LineString><coordinates>2.1,43.9 2.2,43.95</coordinates></LineString></Placemark>
            <Placemark><name>Broken</name><Point><coordinates>x,y</coordinates></Point></Placemark>
          </Folder>
          <Folder><name>OTHER</name><Placemark><name>Street light</name><Point><coordinates>2.2,43.8</coordinates></Point></Placemark></Folder>
        </Document></kml>
        KML;
    }

    public function test_a_kml_gives_the_positions_of_the_boxes_and_the_cabinet(): void
    {
        $result = (new KmlReader)->read($this->kml());

        $this->assertSame(['BPE-0001-FT' => [43.93, 2.148], '900-PB0002' => [43.931, 2.15]], $result['boxes']);
        $this->assertSame(['latitude' => 43.91, 'longitude' => 2.14, 'name' => 'Cabinet'], $result['cabinet']);
    }

    public function test_a_kmz_is_opened_and_without_a_box_folder_every_point_counts(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kmz');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('doc.kml', '<kml xmlns="http://www.opengis.net/kml/2.2"><Document><Placemark><name>SRO_900</name><Point><coordinates>2.1,43.9</coordinates></Point></Placemark><Placemark><name>BPE-0009</name><Point><coordinates>2.2,43.8</coordinates></Point></Placemark></Document></kml>');
        $zip->close();

        $result = (new KmlReader)->read((string) file_get_contents($path));
        unlink($path);

        $this->assertSame(['BPE-0009' => [43.8, 2.2]], $result['boxes']);
        $this->assertSame('SRO_900', $result['cabinet']['name']);
    }

    public function test_unreadable_map_files_are_refused_and_names_are_matched_by_letters_and_digits(): void
    {
        $this->assertNull((new KmlReader)->read('not xml at all'));
        $this->assertNull((new KmlReader)->read('PK-not-a-zip'));
        $this->assertNull((new KmlReader)->read(''));
        $this->assertSame('900PB0002', KmlReader::key('900-PB0002'));
        $this->assertTrue(KmlReader::sameBox('900-PB0002', '900 pb0002'));
        $this->assertTrue(KmlReader::sameBox('PBO-SRO-BPI-81201-0073', '900-PB0073'));
        $this->assertFalse(KmlReader::sameBox('BPE-0001-FT', 'BPE-0002-FT'));
    }
}
