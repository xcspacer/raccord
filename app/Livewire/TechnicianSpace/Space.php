<?php

namespace App\Livewire\TechnicianSpace;

use App\Enums\Feature;
use App\Enums\Module;
use App\Models\Task;
use App\Models\User;
use App\Models\Vehicle;
use App\Settings\ModuleSettings;
use App\TechnicianSpace\DailyReturnEmail;
use App\TechnicianSpace\Timesheet;
use App\TechnicianSpace\VehicleCycle;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * « Mon espace » of a technician: vehicle, monthly timesheet and the « Retour journalier » e-mail.
 */
class Space extends Component
{
    private const array TABS = ['inventory', 'vehicle', 'expense-reports', 'timesheet', 'daily-return'];

    #[Url(except: 'inventory')]
    public string $tab = 'inventory';

    public string $month = '';

    public string $vehicleModel = '';

    public string $vehiclePlate = '';

    public string $returnDate = '';

    public string $team = '';

    /** @var list<array{reference: string, works: string, cables: list<array{description: string, tubes: string}>, welds: string}> */
    public array $boxes = [];

    public string $emailText = '';

    public bool $emailEdited = false;

    public function mount(): void
    {
        $user = $this->user();
        $vehicle = $user->vehicle;

        $this->setTab($this->tab);
        $this->month = CarbonImmutable::now(config('platform.timezone'))->format('Y-m');
        $this->vehicleModel = (string) $vehicle?->model;
        $this->vehiclePlate = (string) $vehicle?->plate;
        $this->resetReturn();
    }

    public function setTab(string $tab): void
    {
        if ($this->blocked()) {
            $tab = 'vehicle';
        }

        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'inventory';

        if (($this->tab === 'expense-reports' && ! Gate::allows('use-feature', Feature::ExpenseReports)) || ($this->tab === 'inventory' && ! $this->inventoryEnabled())) {
            $this->tab = 'timesheet';
        }
    }

    public function saveVehicle(): void
    {
        $this->authorize('create', Vehicle::class);
        $this->validate([
            'vehicleModel' => ['nullable', 'string', 'max:80'],
            'vehiclePlate' => ['nullable', 'string', 'max:20'],
        ]);

        $user = $this->user();
        $attributes = ['model' => trim($this->vehicleModel) ?: null, 'plate' => trim($this->vehiclePlate) ?: null];

        if ($user->vehicle !== null) {
            $user->vehicle->update($attributes);
        } else {
            Vehicle::query()->create($attributes + ['user_id' => $user->id]);
        }

        $this->dispatch('toast', message: __('technician_space.vehicle.saved'));
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'emailText')) {
            $this->emailEdited = true;

            return;
        }

        if (str_starts_with($property, 'boxes') || $property === 'team' || $property === 'returnDate') {
            $this->refreshEmail();
        }
    }

    public function addBox(): void
    {
        $this->boxes[] = $this->newBox();
        $this->refreshEmail(true);
    }

    public function removeBox(int $index): void
    {
        if (count($this->boxes) > 1) {
            unset($this->boxes[$index]);
            $this->boxes = array_values($this->boxes);
            $this->refreshEmail(true);
        }
    }

    public function addCable(int $box): void
    {
        if (isset($this->boxes[$box])) {
            $this->boxes[$box]['cables'][] = ['description' => '', 'tubes' => ''];
            $this->refreshEmail(true);
        }
    }

    public function removeCable(int $box, int $index): void
    {
        if (isset($this->boxes[$box]['cables'][$index]) && count($this->boxes[$box]['cables']) > 1) {
            unset($this->boxes[$box]['cables'][$index]);
            $this->boxes[$box]['cables'] = array_values($this->boxes[$box]['cables']);
            $this->refreshEmail(true);
        }
    }

    public function regenerate(): void
    {
        $this->refreshEmail(true);
    }

    public function render(Timesheet $timesheet): View
    {
        $month = $this->parsedMonth();

        return view('livewire.technician-space.space', [
            'sheet' => $this->tab === 'timesheet' ? $timesheet->forMonth($this->user(), $month) : null,
            'blocked' => $this->blocked(),
            'canInventory' => $this->inventoryEnabled(),
            'canExpenseReports' => Gate::allows('use-feature', Feature::ExpenseReports),
            'expenseReports' => $this->tab === 'expense-reports' ? $this->user()->expenseReports()->latest('created_at')->latest('id')->get() : collect(),
            'monthLabel' => mb_convert_case($month->translatedFormat('F Y'), MB_CASE_TITLE),
        ])->layout('components.layouts.app', ['title' => __('technician_space.title'), 'page' => 'technician-space']);
    }

    private function inventoryEnabled(): bool
    {
        return app(ModuleSettings::class)->isEnabled(Module::Equipment);
    }

    private function blocked(): bool
    {
        return app(VehicleCycle::class)->isBlocked($this->user());
    }

    private function refreshEmail(bool $force = false): void
    {
        if ($this->emailEdited && ! $force) {
            return;
        }

        $date = $this->parsedReturnDate();
        $this->emailText = app(DailyReturnEmail::class)->build($date, $this->team, $this->boxes);
        $this->emailEdited = false;
    }

    private function resetReturn(): void
    {
        $user = $this->user();
        $today = CarbonImmutable::now(config('platform.timezone'))->startOfDay();
        $task = Task::query()->with('workSite.members')->where('user_id', $user->id)->whereDate('date', $today)->whereNotNull('work_site_id')->first();
        $names = $task?->workSite?->members->sortBy(fn (User $member) => $member->getRelation('pivot')->role === 'lead' ? 0 : 1)->pluck('name')->all() ?? [];

        $this->returnDate = $today->toDateString();
        $this->team = $names === [] ? $user->name : implode(' / ', $names);
        $this->boxes = [$this->newBox()];
        $this->refreshEmail(true);
    }

    /**
     * @return array{reference: string, works: string, cables: list<array{description: string, tubes: string}>, welds: string}
     */
    private function newBox(): array
    {
        return ['reference' => '', 'works' => '', 'cables' => [['description' => '', 'tubes' => '']], 'welds' => ''];
    }

    private function parsedMonth(): CarbonImmutable
    {
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) === 1 ? CarbonImmutable::createFromFormat('!Y-m', $this->month) : null;

        return $month ?? CarbonImmutable::now(config('platform.timezone'))->startOfMonth();
    }

    private function parsedReturnDate(): CarbonImmutable
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->returnDate) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $this->returnDate) : null;

        return $date ?? CarbonImmutable::now(config('platform.timezone'))->startOfDay();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
