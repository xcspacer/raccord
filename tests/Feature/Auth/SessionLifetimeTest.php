<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SessionLifetimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_technicians_are_remembered_for_thirty_days(): void
    {
        $response = $this->signIn(Role::Technician);

        $cookie = $response->getCookie($this->recallerName(), false);
        $this->assertNotNull($cookie);
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $cookie->getExpiresTime(), 5);
    }

    public function test_supervisors_and_design_office_are_remembered_for_seven_days(): void
    {
        foreach ([Role::Supervisor, Role::DesignOffice] as $role) {
            $response = $this->signIn($role);

            $cookie = $response->getCookie($this->recallerName(), false);
            $this->assertNotNull($cookie, $role->value);
            $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), $cookie->getExpiresTime(), 5);

            $this->post(route('logout'));
        }
    }

    public function test_administrators_are_never_remembered(): void
    {
        $response = $this->signIn(Role::Administrator);

        $this->assertNull($response->getCookie($this->recallerName(), false));
    }

    public function test_administrator_session_ends_after_two_hours_of_inactivity(): void
    {
        $this->signIn(Role::Administrator);

        $this->travel(119)->minutes();
        $this->nextRequest()->get('/')->assertOk();

        $this->travel(121)->minutes();
        $this->nextRequest()->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_administrator_session_ends_twelve_hours_after_sign_in_even_when_active(): void
    {
        $this->signIn(Role::Administrator);

        foreach (range(1, 11) as $hour) {
            $this->travel(1)->hours();
            $this->nextRequest()->get('/')->assertOk();
        }

        $this->travel(61)->minutes();
        $this->nextRequest()->get('/')->assertRedirect('/login');
    }

    public function test_supervisor_session_ends_after_eight_hours_of_inactivity_without_remember_cookie(): void
    {
        $this->signIn(Role::Supervisor);

        $this->travel(7 * 60 + 59)->minutes();
        $this->nextRequest()->get('/')->assertOk();

        $this->travel(8 * 60 + 1)->minutes();
        $this->nextRequest()->get('/')->assertRedirect('/login');
    }

    public function test_technician_session_lasts_twelve_hours_of_inactivity(): void
    {
        $this->signIn(Role::Technician);

        $this->travel(11 * 60 + 59)->minutes();
        $this->nextRequest()->get('/')->assertOk();

        $this->travel(12 * 60 + 1)->minutes();
        $this->nextRequest()->get('/')->assertRedirect('/login');
    }

    public function test_remembered_technician_signs_in_again_after_inactivity(): void
    {
        $response = $this->signIn(Role::Technician);
        $recaller = $response->getCookie($this->recallerName());

        $this->travel(13)->hours();

        $this->nextRequest()->withCookie($this->recallerName(), (string) $recaller?->getValue())->get('/')->assertOk();
        $this->assertAuthenticated();
    }

    public function test_remember_cookie_is_refused_once_the_role_is_never_remembered(): void
    {
        $response = $this->signIn(Role::Technician);
        $recaller = $response->getCookie($this->recallerName());
        $user = User::query()->sole();
        $user->syncRoles([Role::Administrator->value]);

        $this->travel(13)->hours();

        $this->nextRequest()->withCookie($this->recallerName(), (string) $recaller?->getValue())->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_background_polling_does_not_count_as_activity(): void
    {
        $this->signIn(Role::Administrator);

        $this->travel(90)->minutes();
        $this->nextRequest()->withHeader('X-Livewire', 'true')->post(route('locale.update'), [
            'components' => [['snapshot' => '{}', 'updates' => [], 'calls' => [['method' => '$refresh', 'params' => [], 'metadata' => ['type' => 'poll']]]]],
        ]);

        $this->travel(40)->minutes();
        $this->nextRequest()->get('/')->assertRedirect('/login');
    }

    /**
     * @return TestResponse<Response>
     */
    private function signIn(Role $role): TestResponse
    {
        $user = User::factory()->withRole($role)->create(['username' => 'person-'.$role->value]);

        return $this->post('/login', ['username' => $user->username, 'password' => 'password'])->assertRedirect('/');
    }

    /**
     * A new request starts with a fresh guard, as in production, while the session stays.
     */
    private function nextRequest(): static
    {
        Auth::forgetGuards();

        return $this;
    }

    private function recallerName(): string
    {
        return Auth::guard('web')->getRecallerName();
    }
}
