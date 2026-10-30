<?php

namespace App\Livewire\Audit;

use App\Audit\BoxName;
use App\Audit\Recheck\BoxRecheck;
use App\Audit\Recheck\RecheckPlanException;
use App\Enums\AuditMode;
use App\Enums\AuditSpliceType;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditBoxRecheck;
use App\Models\AuditCable;
use App\Models\AuditPlan;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The recheck of a box against its plan (optical mode): for each splice line the technician sets what is really
 * in the box (type, fibre and tube B, cable B, and the input when it differs). Every change is kept on the server.
 *
 * @phpstan-import-type Line from BoxRecheck
 */
class BoxRecheckPage extends Component
{
    #[Locked]
    public string $cabinet = '';

    #[Locked]
    public int $boxId = 0;

    /**
     * A manager's « Modify » mode: the only way to change the recheck of a validated audit.
     */
    #[Locked]
    public bool $editing = false;

    public string $filter = 'all';

    /** @var list<string> */
    public array $collapsed = [];

    /** @var list<int> */
    public array $editingInput = [];

    /** @var array<string, array{cable: string, lines: list<int>}> */
    public array $extraCable = [];

    public string $manualTarget = '';

    public string $manualCable = '';

    public function mount(string $cabinet, int $box, BoxRecheck $recheck): void
    {
        $audit = $this->audit($cabinet);
        $model = AuditBox::query()->where('audit_id', $audit->id)->whereKey($box)->whereNull('removed_at')->firstOrFail();
        abort_unless($audit->effectiveMode() === AuditMode::Optical && $recheck->planFor($model, $audit) !== null, 404);

        $this->cabinet = $audit->cabinet_key;
        $this->boxId = $model->id;
        $this->editing = request()->boolean('edit') && $this->isManager();

        try {
            if (count($recheck->lines($this->plan($recheck), $this->record($recheck))) > 36) {
                $this->collapsed = array_keys($recheck->cassetteNumbers($this->plan($recheck), $this->record($recheck)));
            }
        } catch (RecheckPlanException) {
            $this->collapsed = [];
        }
    }

    public function startEditing(): void
    {
        abort_unless($this->isManager(), 403);
        $this->editing = true;
    }

    public function stopEditing(): void
    {
        $this->editing = false;
    }

    public function leave(): void
    {
        $this->redirect(route('audits.box', ['cabinet' => $this->cabinet, 'name' => $this->box()->name]), navigate: true);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter === 'todo' ? 'todo' : 'all';
    }

    public function toggleCassette(string $cassette): void
    {
        $this->collapsed = in_array($cassette, $this->collapsed, true) ? array_values(array_diff($this->collapsed, [$cassette])) : [...$this->collapsed, $cassette];
    }

    public function toggleInput(int $index): void
    {
        $this->editingInput = in_array($index, $this->editingInput, true) ? array_values(array_diff($this->editingInput, [$index])) : [...$this->editingInput, $index];
    }

    public function setType(int $index, string $type, BoxRecheck $recheck): void
    {
        if (AuditSpliceType::tryFrom($type) !== null) {
            $this->change($recheck, [$index], ['type' => $type]);
        }
    }

    public function setColour(int $index, string $field, int $number, BoxRecheck $recheck): void
    {
        $prefix = ['fibre_b' => 'F', 'tube_b' => 'T', 'fibre_a' => 'F', 'tube_a' => 'T'][$field] ?? null;

        if ($prefix !== null && $number >= 0 && $number <= 12) {
            $this->change($recheck, [$index], [$field => $number === 0 ? '' : $prefix.str_pad((string) $number, 2, '0', STR_PAD_LEFT)]);
        }
    }

    public function setCable(int $index, string $field, string $value, BoxRecheck $recheck): void
    {
        if (! in_array($field, ['cable_a', 'cable_b'], true)) {
            return;
        }

        if ($value === '__manual') {
            $this->askManual($field.':'.$index);

            return;
        }

        $this->change($recheck, [$index], [$field => $this->allowedCable($recheck, $value)]);
    }

    public function setCassetteType(string $cassette, string $type, BoxRecheck $recheck): void
    {
        if (AuditSpliceType::tryFrom($type) !== null) {
            $this->change($recheck, $this->indexesOf($recheck, $cassette), ['type' => $type]);
        }
    }

    public function setCassetteCable(string $cassette, string $field, string $value, BoxRecheck $recheck): void
    {
        if (! in_array($field, ['cable_a', 'cable_b'], true) || $value === '') {
            return;
        }

        if ($value === '__manual') {
            $this->askManual('cassette_'.$field.':'.$cassette);

            return;
        }

        $this->change($recheck, $this->indexesOf($recheck, $cassette), [$field => $this->allowedCable($recheck, $value)]);
    }

    /**
     * « Straight »: every fibre of the cassette is spliced on the same colour of cable B.
     */
    public function straight(string $cassette, BoxRecheck $recheck): void
    {
        $this->authorizeEdit();
        $lines = collect($this->lines($recheck))->where('cassette', $cassette);
        $common = $lines->pluck('cable_b')->unique()->count() === 1 ? (string) $lines->first()['cable_b'] : '';
        $boxCables = $this->boxCables();
        $cable = $common !== '' ? $common : (count($boxCables) === 1 ? $boxCables[0] : '');

        foreach ($lines as $line) {
            $fibre = self::number($line['fibre_a']);
            $tube = self::number($line['tube_a']);
            $recheck->change($this->plan($recheck), $this->record($recheck), [$line['index']], [
                'type' => AuditSpliceType::Splice->value,
                'fibre_b' => $fibre > 0 ? 'F'.str_pad((string) $fibre, 2, '0', STR_PAD_LEFT) : $line['fibre_b'],
                'tube_b' => $tube > 0 ? 'T'.str_pad((string) $tube, 2, '0', STR_PAD_LEFT) : $line['tube_b'],
                'cable_b' => $cable !== '' ? $cable : $line['cable_b'],
            ]);
        }
    }

    public function conformCassette(string $cassette, BoxRecheck $recheck): void
    {
        $this->authorizeEdit();
        $recheck->conformCassette($this->plan($recheck), $this->record($recheck), $cassette);
    }

    public function setCassetteNumber(string $cassette, string $value, BoxRecheck $recheck): void
    {
        $this->authorizeEdit();
        $recheck->setCassetteNumber($this->record($recheck), $cassette, $value);
    }

    public function toggleExtraCable(string $cassette): void
    {
        $this->authorizeEdit();

        if (isset($this->extraCable[$cassette])) {
            unset($this->extraCable[$cassette]);
        } else {
            $this->extraCable[$cassette] = ['cable' => '', 'lines' => []];
        }
    }

    public function setExtraCable(string $cassette, string $value, BoxRecheck $recheck): void
    {
        if (! isset($this->extraCable[$cassette])) {
            return;
        }

        if ($value === '__manual') {
            $this->askManual('extra:'.$cassette);

            return;
        }

        $this->extraCable[$cassette]['cable'] = $this->allowedCable($recheck, $value);
    }

    public function toggleExtraLine(string $cassette, int $index): void
    {
        if (isset($this->extraCable[$cassette])) {
            $lines = $this->extraCable[$cassette]['lines'];
            $this->extraCable[$cassette]['lines'] = in_array($index, $lines, true) ? array_values(array_diff($lines, [$index])) : [...$lines, $index];
        }
    }

    public function toggleAllExtraLines(string $cassette, BoxRecheck $recheck): void
    {
        if (isset($this->extraCable[$cassette])) {
            $all = $this->indexesOf($recheck, $cassette);
            $this->extraCable[$cassette]['lines'] = array_diff($all, $this->extraCable[$cassette]['lines']) === [] ? [] : $all;
        }
    }

    public function applyExtraCable(string $cassette, BoxRecheck $recheck): void
    {
        $state = $this->extraCable[$cassette] ?? null;

        if ($state === null || $state['cable'] === '' || $state['lines'] === []) {
            return;
        }

        $this->change($recheck, array_values(array_intersect($state['lines'], $this->indexesOf($recheck, $cassette))), ['cable_b' => $state['cable']]);
        $this->extraCable[$cassette] = ['cable' => $state['cable'], 'lines' => []];
    }

    /**
     * Writes the RECNOK workbook with the changes made.
     */
    public function save(BoxRecheck $recheck): void
    {
        $this->authorizeEdit();
        $this->writeWorkbook($recheck, false, 'audit.recheck.saved');
    }

    /**
     * The box is conform to its plan: the RECNOK is an exact copy of the plan.
     */
    public function conform(BoxRecheck $recheck): void
    {
        $this->authorizeEdit();
        $record = $this->record($recheck);

        if ($recheck->isBoxConform($record, $this->lines($recheck))) {
            if ($this->box()->recheck_state === 'nonconforming') {
                $recheck->setNonConforming($this->box(), false);
            }

            return;
        }

        $this->writeWorkbook($recheck, true, 'audit.recheck.conform_saved');
    }

    public function toggleNonConform(BoxRecheck $recheck): void
    {
        $this->authorizeEdit();
        $recheck->setNonConforming($this->box(), $this->box()->recheck_state !== 'nonconforming');
    }

    private function writeWorkbook(BoxRecheck $recheck, bool $conform, string $message): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        try {
            $recheck->save($this->box(), $this->plan($recheck), $this->record($recheck), $user, $conform);
        } catch (RecheckPlanException) {
            $this->addError('plan', __('audit.recheck.plan_error'));

            return;
        }

        if ($conform) {
            $recheck->setNonConforming($this->box(), false);
        }

        $this->dispatch('toast', message: __($message, ['name' => $this->record($recheck)->recnok_name]));
    }

    public function saveManualCable(BoxRecheck $recheck): void
    {
        $this->authorizeEdit();
        $this->validate(['manualCable' => ['required', 'string', 'max:160']]);
        $name = $recheck->addManualCable($this->record($recheck), $this->manualCable);
        [$kind, $target] = explode(':', $this->manualTarget, 2) + [1 => ''];

        match ($kind) {
            'cable_a', 'cable_b' => $recheck->change($this->plan($recheck), $this->record($recheck), [(int) $target], [$kind => $name]),
            'cassette_cable_a', 'cassette_cable_b' => $recheck->change($this->plan($recheck), $this->record($recheck), $this->indexesOf($recheck, $target), [substr($kind, 9) => $name]),
            'extra' => isset($this->extraCable[$target]) ? $this->extraCable[$target]['cable'] = $name : null,
            default => null,
        };

        $this->cancelManual();
    }

    public function cancelManual(): void
    {
        $this->manualTarget = '';
        $this->manualCable = '';
        $this->resetErrorBag();
    }

    public function render(BoxRecheck $recheck): View
    {
        $audit = $this->audit($this->cabinet);
        $box = $this->box();
        $plan = $this->plan($recheck);
        $record = $this->record($recheck);
        $error = null;
        $lines = $numbers = $planCables = [];

        try {
            $lines = $recheck->lines($plan, $record);
            $numbers = $recheck->cassetteNumbers($plan, $record);
            $planCables = $recheck->planCables($plan);
        } catch (RecheckPlanException) {
            $error = __('audit.recheck.plan_error');
        }

        $cassettes = collect($lines)->groupBy('cassette')->map(fn ($group, $name) => [
            'name' => (string) $name,
            'lines' => $group->all(),
            'counts' => self::counts($group->all()),
            'conform' => $recheck->isCassetteConform($record, $lines, (string) $name),
            'straight' => $group->every(fn (array $line) => $line['type'] === AuditSpliceType::Splice->value && self::number($line['fibre_a']) > 0 && self::number($line['fibre_b']) === self::number($line['fibre_a']) && self::number($line['tube_b']) === self::number($line['tube_a'])),
            'cable_a' => $group->pluck('cable_a')->unique()->count() === 1 ? (string) $group->first()['cable_a'] : '',
            'cable_b' => $group->pluck('cable_b')->unique()->count() === 1 ? (string) $group->first()['cable_b'] : '',
            'number' => $numbers[$name] ?? ['default' => '', 'value' => ''],
        ])->values();

        return view('livewire.audit.box-recheck-page', [
            'box' => $box,
            'boxConform' => $error === null && $recheck->isBoxConform($record, $lines),
            'modified' => collect($lines)->contains('changed', true),
            'validated' => $audit->isValidated(),
            'isManager' => $this->isManager(),
            'planName' => $plan->name,
            'error' => $error,
            'canEdit' => $this->canEdit($audit),
            'cassettes' => $cassettes,
            'counts' => self::counts($lines),
            'types' => AuditSpliceType::cases(),
            'cables' => ['box' => $this->boxCables(), 'plan' => array_values(array_diff($planCables, $this->boxCables(), $record->draft['manual_cables'] ?? [])), 'manual' => $record->draft['manual_cables'] ?? []],
            'record' => $record,
            'paperPhotos' => $box->photos()->where('folder', 'recheck')->count(),
            'backUrl' => route('audits.box', ['cabinet' => $this->cabinet, 'name' => $box->name]),
        ])->layout('components.layouts.app', ['title' => '🔧 '.__('audit.recheck.title').' · '.$box->name, 'page' => 'audits', 'focus' => true]);
    }

    /**
     * Number of lines of each type, by type value.
     *
     * @param  array<int, Line>  $lines
     * @return array<string, int>
     */
    private static function counts(array $lines): array
    {
        return collect(AuditSpliceType::cases())->mapWithKeys(fn (AuditSpliceType $type) => [$type->value => collect($lines)->where('type', $type->value)->count()])->all();
    }

    /**
     * Colour number (1 to 12) of a fibre or tube code such as « F03 » or « T12 »; 0 when none.
     */
    public static function number(string $code): int
    {
        return preg_match('/(\d+)/', $code, $match) === 1 ? (int) $match[1] : 0;
    }

    /**
     * @param  list<int>  $indexes
     * @param  array<string, string>  $patch
     */
    private function change(BoxRecheck $recheck, array $indexes, array $patch): void
    {
        $this->authorizeEdit();

        try {
            $recheck->change($this->plan($recheck), $this->record($recheck), $indexes, $patch);
        } catch (RecheckPlanException) {
            $this->addError('plan', __('audit.recheck.plan_error'));
        }
    }

    private function askManual(string $target): void
    {
        $this->authorizeEdit();
        $this->manualTarget = $target;
        $this->manualCable = '';
    }

    /**
     * A cable picked from the lists offered (cables of the box, of the plan, typed by hand), never anything else.
     */
    private function allowedCable(BoxRecheck $recheck, string $value): string
    {
        $allowed = [...$this->boxCables(), ...$recheck->planCables($this->plan($recheck)), ...($this->record($recheck)->draft['manual_cables'] ?? [])];

        return in_array($value, $allowed, true) ? $value : '';
    }

    /**
     * @return list<int>
     */
    private function indexesOf(BoxRecheck $recheck, string $cassette): array
    {
        return collect($this->lines($recheck))->where('cassette', $cassette)->pluck('index')->values()->all();
    }

    /**
     * @return list<Line>
     */
    private function lines(BoxRecheck $recheck): array
    {
        try {
            return $recheck->lines($this->plan($recheck), $this->record($recheck));
        } catch (RecheckPlanException) {
            return [];
        }
    }

    /**
     * Cables recorded on the box, named « Cable n · capacity · type » as on the box screen.
     *
     * @return list<string>
     */
    private function boxCables(): array
    {
        return $this->box()->cables->values()->map(fn (AuditCable $cable, int $index) => implode(' · ', array_filter([__('audit.field.cable_n', ['n' => $index + 1]), $cable->capacity, $cable->fibre_type?->label()])))->all();
    }

    private function authorizeEdit(): void
    {
        $audit = $this->audit($this->cabinet);
        $this->authorize('recheck', $audit);
        abort_unless($this->canEdit($audit), 403);
    }

    /**
     * Everyone working on the audit until it is validated (AuditPolicy::recheck); afterwards only a manager, once in
     * « Modify » mode.
     */
    private function canEdit(Audit $audit): bool
    {
        return auth()->user()?->can('recheck', $audit) === true && (! $audit->isValidated() || $this->editing);
    }

    private function isManager(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('manage', Audit::class);
    }

    private function audit(string $cabinet): Audit
    {
        $audit = Audit::query()->where('cabinet_key', BoxName::cabinetKey($cabinet))->firstOrFail();
        $this->authorize('work', $audit);

        return $audit;
    }

    private function box(): AuditBox
    {
        return AuditBox::query()->with('cables')->whereKey($this->boxId)->firstOrFail();
    }

    private function plan(BoxRecheck $recheck): AuditPlan
    {
        $box = $this->box();

        return $recheck->planFor($box, $box->audit) ?? abort(404);
    }

    private function record(BoxRecheck $recheck): AuditBoxRecheck
    {
        return $recheck->record($this->box());
    }
}
