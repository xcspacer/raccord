<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_refused_page_shows_the_translated_notice_without_unsigned_inline_styles(): void
    {
        $this->seed(RoleSeeder::class);
        $technician = User::factory()->withRole(Role::Technician)->create();

        $response = $this->actingAs($technician)->get('/admin/users')->assertForbidden();

        $response->assertSee(__('errors.403.title'))->assertSee(__('errors.403.message'))->assertSee(__('errors.home'));
        $this->assertDoesNotMatchRegularExpression('/<style(?![^>]*nonce)/', $response->getContent());
    }

    public function test_an_unknown_page_shows_the_not_found_notice(): void
    {
        $this->get('/this-page-does-not-exist')->assertNotFound()->assertSee(__('errors.404.title'))->assertSee(__('errors.home'));
    }

    public function test_json_errors_carry_the_translated_message_instead_of_the_framework_one(): void
    {
        $this->seed(RoleSeeder::class);
        $technician = User::factory()->withRole(Role::Technician)->create(['locale' => 'fr']);

        $this->getJson('/this-page-does-not-exist')->assertNotFound()->assertExactJson(['message' => __('errors.404.message')]);
        $this->actingAs($technician)->getJson('/admin/users')->assertForbidden()->assertExactJson(['message' => __('errors.403.message', [], 'fr')]);
    }

    public function test_pages_hand_the_reload_questions_to_the_scripts(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('<meta name="expired-page-question" content="'.e(__('errors.419.reload_question')).'">', false)
            ->assertSee('<meta name="empty-response-question" content="'.e(__('errors.empty_response_question')).'">', false);
    }
}
