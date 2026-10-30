<?php

namespace Tests\Unit\Audit;

use App\Audit\Import\SynopticListParser;
use App\Audit\Import\SynopticPdfReader;
use Barryvdh\DomPDF\Facade\Pdf;
use Tests\TestCase;

class SynopticPdfReaderTest extends TestCase
{
    public function test_the_text_of_a_pdf_gives_the_boxes_line_by_line(): void
    {
        config(['audit.box_suffixes' => ['FT']]);
        $pdf = Pdf::loadHTML('<p>BPE-0001-FT [CHAMBRE : 12]</p><p>BPE 144 FO</p><p>900-PB0002 [POTEAU : 7]</p><p>PBO size 1</p><p>1 main street</p>')->output();

        $boxes = (new SynopticListParser)->parse((new SynopticPdfReader)->text($pdf));

        $this->assertSame(['BPE-0001-FT', '900-PB0002'], array_column($boxes, 'name'));
        $this->assertSame('12', $boxes[0]['support_number']);
        $this->assertSame('1 main street', $boxes[1]['address']);
    }

    public function test_files_that_are_not_readable_pdfs_give_no_text(): void
    {
        $reader = new SynopticPdfReader;

        $this->assertSame('', $reader->text('plain text'));
        $this->assertSame('', $reader->text('%PDF-1.4 broken'));
        $this->assertSame('', $reader->text(''));
    }
}
