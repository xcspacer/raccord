<?php

namespace Tests\Feature\Ai;

use App\Ai\AiException;
use App\Ai\AiQuota;
use App\Ai\AnthropicClient;
use App\Ai\VehiclePhotoComparison;
use App\Enums\AiFeature;
use App\Enums\Role;
use App\Enums\VehicleDamageSeverity;
use App\Enums\VehiclePhotoSlot;
use App\Jobs\CompareVehiclePhotos;
use App\Livewire\TechnicianSpace\VehicleInspection;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\VehiclePhoto;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class VehiclePhotoComparisonTest extends TestCase
{
    use RefreshDatabase;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['services.anthropic.key' => 'test-key', 'services.anthropic.url' => 'https://anthropic.test/v1/messages']);
        $this->seed(RoleSeeder::class);
        $this->technician = User::factory()->withRole(Role::Technician)->create();
    }

    private function photo(string $label, int $width = 1600, int $height = 1200): VehiclePhoto
    {
        $image = imagecreatetruecolor($width, $height);
        imagestring($image, 5, 10, 10, $label, (int) imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagejpeg($image);
        $content = (string) ob_get_clean();
        $path = 'files/'.hash('sha256', $content).'.jpg';
        Storage::disk('local')->put($path, $content);
        $file = StoredFile::query()->create(['sha256' => hash('sha256', $content), 'path' => $path, 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => strlen($content)]);

        return VehiclePhoto::query()->create(['user_id' => $this->technician->id, 'slot' => VehiclePhotoSlot::Front, 'stored_file_id' => $file->id, 'month' => '2026-11', 'taken_at' => now()]);
    }

    private function fakeAnswer(string $text): void
    {
        Http::fake(['anthropic.test/*' => Http::response(['content' => [['type' => 'text', 'text' => $text]]])]);
    }

    public function test_a_detected_damage_is_normalised_and_kept_on_the_replaced_photo(): void
    {
        $this->technician->update(['locale' => 'pt']);
        $this->fakeAnswer('Result: {"damage_detected":true,"description":"'.str_repeat('x', 400).'","severity":"extreme","confidence":"high"}');
        $before = $this->photo('old');
        $after = $this->photo('new');

        Bus::dispatchSync(new CompareVehiclePhotos($this->technician->id, $before->id, $after->id));

        $comparison = $before->refresh()->comparison;
        $this->assertTrue($comparison['damage_detected']);
        $this->assertSame(300, mb_strlen($comparison['description']));
        $this->assertSame('none', $comparison['severity']);
        $this->assertSame('high', $comparison['confidence']);

        Http::assertSent(function (Request $request): bool {
            $content = $request['messages'][0]['content'];
            $sent = getimagesizefromstring(base64_decode($content[0]['source']['data']));

            return $sent !== false && $sent[0] === 1600 && $sent[1] === 1200 - VehiclePhotoComparison::stampHeight(1600)
                && $request->hasHeader('x-api-key', 'test-key') && $request['max_tokens'] === 350
                && $content[0]['type'] === 'image' && $content[1]['type'] === 'image' && $content[2]['type'] === 'text'
                && str_contains($content[2]['text'], '"severity":"none|minor|severe"') && str_contains($content[2]['text'], 'looking at the report: Portuguese (Portugal).');
        });
    }

    public function test_an_unreadable_answer_or_a_failing_service_is_recorded_without_a_damage(): void
    {
        Http::fake(['anthropic.test/*' => Http::sequence()->push(['content' => [['type' => 'text', 'text' => 'no json here']]])->push([], 500)]);
        $before = $this->photo('old');
        $after = $this->photo('new');

        Bus::dispatchSync(new CompareVehiclePhotos($this->technician->id, $before->id, $after->id));
        $this->assertSame('unreadable_answer', $before->refresh()->comparison['error']);

        Bus::dispatchSync(new CompareVehiclePhotos($this->technician->id, $before->id, $after->id));
        $this->assertSame('unavailable', $before->refresh()->comparison['error']);
    }

    public function test_nothing_is_sent_without_an_api_key(): void
    {
        config(['services.anthropic.key' => '']);
        Http::fake();
        $before = $this->photo('old');
        $after = $this->photo('new');

        Bus::dispatchSync(new CompareVehiclePhotos($this->technician->id, $before->id, $after->id));

        Http::assertNothingSent();
        $this->assertNull($before->refresh()->comparison);
        $this->assertFalse(app(AnthropicClient::class)->isConfigured());
    }

    public function test_the_daily_quota_is_counted_per_person_and_for_the_installation(): void
    {
        config(['services.anthropic.quotas.vehicle_compare' => ['per_user' => 2, 'global' => 3, 'per_user_hour' => 10]]);
        $other = User::factory()->withRole(Role::Technician)->create();
        $quota = app(AiQuota::class);

        $quota->consume($this->technician, AiFeature::CompareVehiclePhotos);
        $quota->consume($this->technician, AiFeature::CompareVehiclePhotos);

        try {
            $quota->consume($this->technician, AiFeature::CompareVehiclePhotos);
            $this->fail('The per-person limit was not enforced.');
        } catch (AiException $exception) {
            $this->assertSame('quota_reached', $exception->reason);
        }

        $quota->consume($other, AiFeature::CompareVehiclePhotos);

        $this->expectException(AiException::class);
        $quota->consume($other, AiFeature::CompareVehiclePhotos);
    }

    public function test_the_quota_starts_again_the_next_day(): void
    {
        config(['services.anthropic.quotas.vehicle_compare' => ['per_user' => 1, 'global' => 5, 'per_user_hour' => 10]]);
        $quota = app(AiQuota::class);

        $quota->consume($this->technician, AiFeature::CompareVehiclePhotos);
        $this->travel(1)->day();
        $quota->consume($this->technician, AiFeature::CompareVehiclePhotos);

        $this->assertDatabaseCount('ai_usage', 2);
    }

    public function test_the_hourly_quota_limits_a_person_within_the_last_hour(): void
    {
        $this->travelTo(now()->utc()->setTime(10, 0));
        config(['services.anthropic.quotas.vehicle_compare' => ['per_user' => 10, 'global' => 10, 'per_user_hour' => 2]]);
        $quota = app(AiQuota::class);

        $quota->consume($this->technician, AiFeature::CompareVehiclePhotos);
        $quota->consume($this->technician, AiFeature::CompareVehiclePhotos);

        try {
            $quota->consume($this->technician, AiFeature::CompareVehiclePhotos);
            $this->fail('The hourly limit was not enforced.');
        } catch (AiException $exception) {
            $this->assertSame('quota_reached', $exception->reason);
        }

        $quota->consume(User::factory()->withRole(Role::Technician)->create(), AiFeature::CompareVehiclePhotos);

        $this->travel(61)->minutes();
        $quota->consume($this->technician, AiFeature::CompareVehiclePhotos);

        $this->assertDatabaseCount('ai_usage', 4);
    }

    public function test_replacing_a_photo_schedules_the_comparison_but_the_first_photo_does_not(): void
    {
        Bus::fake();
        $component = Livewire::actingAs($this->technician)->test(VehicleInspection::class)
            ->set('photo', UploadedFile::fake()->image('a.jpg', 800, 600))->call('storePhoto', 'front', []);

        Bus::assertNotDispatchedAfterResponse(CompareVehiclePhotos::class);

        $component->set('photo', UploadedFile::fake()->image('b.jpg', 640, 480))->call('storePhoto', 'front', []);

        Bus::assertDispatchedAfterResponse(CompareVehiclePhotos::class, fn (CompareVehiclePhotos $job) => $job->userId === $this->technician->id);
    }

    public function test_a_detected_damage_is_shown_to_the_technician_only(): void
    {
        $before = $this->photo('old');
        $before->update(['archived_at' => now(), 'comparison' => ['damage_detected' => true, 'description' => 'Scratch on the bumper', 'severity' => 'minor', 'confidence' => 'high']]);

        Livewire::actingAs($this->technician)->test(VehicleInspection::class)->assertSee('Scratch on the bumper')
            ->assertSee(__('vehicle_inspection.degradation', ['slot' => VehiclePhotoSlot::Front->label(), 'severity' => VehicleDamageSeverity::Minor->label()]));
        Livewire::actingAs(User::factory()->withRole(Role::Technician)->create())->test(VehicleInspection::class)->assertDontSee('Scratch on the bumper');
    }

    public function test_the_technician_is_told_the_photos_go_to_an_external_ai_service_only_when_it_is_on(): void
    {
        Livewire::actingAs($this->technician)->test(VehicleInspection::class)
            ->assertSee(__('profile.privacy.ai_notice'))
            ->assertSeeHtml("\$dispatch('open-profile')");

        config(['services.anthropic.key' => '']);

        Livewire::actingAs($this->technician)->test(VehicleInspection::class)->assertDontSee(__('profile.privacy.ai_notice'));
    }
}
