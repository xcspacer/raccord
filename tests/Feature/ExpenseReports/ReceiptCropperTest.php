<?php

namespace Tests\Feature\ExpenseReports;

use App\Enums\Role;
use App\Expenses\ReceiptCropper;
use App\Jobs\ReadReceipt;
use App\Livewire\ExpenseReports\Editor;
use App\Models\ExpenseDraft;
use App\Models\ExpenseLine;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ReceiptCropperTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A 1200 × 900 photo of a dark table with the given light (or coloured) rectangles: [x, y, width, height, red, green, blue].
     *
     * @param  list<array{int, int, int, int, int, int, int}>  $rectangles
     */
    private function photo(array $rectangles, int $background = 25): UploadedFile
    {
        $image = imagecreatetruecolor(1200, 900);
        imagefill($image, 0, 0, imagecolorallocate($image, $background, $background, $background));

        foreach ($rectangles as [$x, $y, $width, $height, $red, $green, $blue]) {
            imagefilledrectangle($image, $x, $y, $x + $width, $y + $height, imagecolorallocate($image, $red, $green, $blue));
        }

        ob_start();
        imagejpeg($image, null, 92);

        return UploadedFile::fake()->createWithContent('photo.jpg', (string) ob_get_clean());
    }

    /**
     * @return array{int, int}
     */
    private function dimensionsOf(string $jpeg): array
    {
        $image = imagecreatefromstring($jpeg);

        return [imagesx($image), imagesy($image)];
    }

    public function test_each_receipt_of_a_photo_is_cut_out_in_reading_order(): void
    {
        $pieces = app(ReceiptCropper::class)->pieces($this->photo([
            [700, 80, 380, 330, 235, 235, 235],
            [90, 120, 300, 640, 240, 238, 236],
            [700, 500, 380, 300, 230, 230, 230],
        ]));

        $this->assertCount(3, $pieces);
        [$width, $height] = $this->dimensionsOf($pieces[0]);
        $this->assertEqualsWithDelta(300, $width, 60);
        $this->assertEqualsWithDelta(640, $height, 80);
        $this->assertEqualsWithDelta(330, $this->dimensionsOf($pieces[1])[1], 80);
        $this->assertEqualsWithDelta(300, $this->dimensionsOf($pieces[2])[1], 80);
    }

    public function test_receipts_touching_each_other_by_a_thin_bridge_are_separated(): void
    {
        $pieces = app(ReceiptCropper::class)->pieces($this->photo([
            [100, 100, 380, 600, 235, 235, 235],
            [520, 100, 380, 600, 235, 235, 235],
            [470, 380, 60, 20, 235, 235, 235],
        ]));

        $this->assertCount(2, $pieces);
    }

    public function test_the_whole_photo_is_kept_when_there_is_nothing_to_cut(): void
    {
        $cropper = app(ReceiptCropper::class);

        $this->assertCount(1, $cropper->pieces($this->photo([])));
        $this->assertCount(1, $cropper->pieces($this->photo([[0, 0, 1199, 899, 235, 235, 235]])));
        $this->assertCount(1, $cropper->pieces($this->photo([[100, 100, 500, 500, 255, 200, 20]])));
        $this->assertCount(1, $cropper->pieces($this->photo([[200, 100, 500, 650, 235, 235, 235]])));
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(ReceiptCropper::class)->pieces(UploadedFile::fake()->createWithContent('doc.jpg', 'not an image'));
    }

    public function test_the_editor_creates_a_line_per_receipt_and_remembers_the_choice(): void
    {
        Storage::fake('local');
        Bus::fake();
        $this->seed(RoleSeeder::class);
        $technician = User::factory()->withRole(Role::Technician)->create();
        $content = $this->photo([[80, 100, 380, 640, 235, 235, 235], [700, 100, 380, 640, 235, 235, 235]]);

        $component = Livewire::actingAs($technician)->test(Editor::class)->set('photos', [$content])->assertHasNoErrors();

        $this->assertSame(2, ExpenseLine::query()->count());
        Bus::assertDispatchedAfterResponse(ReadReceipt::class, 2);

        $component->set('autocrop', false)->set('photos', [$this->photo([[80, 100, 380, 640, 235, 235, 235], [700, 100, 380, 640, 235, 235, 235]])]);

        $this->assertSame(3, ExpenseLine::query()->count());
        $this->assertFalse(ExpenseDraft::query()->sole()->autocrop);
        $this->assertFalse(Livewire::actingAs($technician)->test(Editor::class)->get('autocrop'));
    }

    public function test_pieces_beyond_the_limit_are_ignored_with_a_warning(): void
    {
        Storage::fake('local');
        Bus::fake();
        $this->seed(RoleSeeder::class);
        $technician = User::factory()->withRole(Role::Technician)->create();

        foreach (range(1, 31) as $i) {
            ExpenseLine::query()->create(['user_id' => $technician->id, 'nature' => 'other', 'status' => 'manual']);
        }

        Livewire::actingAs($technician)->test(Editor::class)
            ->set('photos', [$this->photo([[80, 100, 380, 640, 235, 235, 235], [700, 100, 380, 640, 235, 235, 235]])])
            ->assertHasErrors('photos');

        $this->assertSame(32, ExpenseLine::query()->count());
    }
}
