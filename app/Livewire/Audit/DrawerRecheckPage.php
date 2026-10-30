<?php

namespace App\Livewire\Audit;

use App\Audit\BoxName;
use App\Audit\Drawer\DrawerPlan;
use App\Audit\Drawer\DrawerRecheck;
use App\Audit\Recheck\RecheckPlanException;
use App\Models\Audit;
use App\Models\AuditBox;
use App\Models\AuditDrawerRecheck;
use App\Models\AuditPlan;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The recheck of the drawer plan of a cabinet: an overview of each drawer (modules × positions, PBO cards), a guided
 * mode that goes through the PBO one by one, and the edit of a PBO. Everything is kept on the server.
 *
 * @phpstan-import-type Segment from DrawerPlan
 * @phpstan-import-type Cells from DrawerPlan
 * @phpstan-import-type Drawer from DrawerPlan
 * @phpstan-import-type EditSegment from DrawerRecheck
 *
 * @phpstan-type State array{drawers: list<Drawer>, original: Cells, current: Cells}
 */
class DrawerRecheckPage extends Component
{
    #[Locked]
    public string $cabinet = '';

    public int $tab = 0;

    public string $search = '';

    public bool $guided = false;

    public int $guideIndex = 0;

    /** @var array{orig: string, name: string, manual: bool, segs: list<EditSegment>, newCable: array{i: int, cap: int, dest: string}|null, extraCables?: list<string>}|null */
    public ?array $edit = null;

    /** @var list<string> */
    public array $clash = [];

    public string $error = '';

    public function mount(string $cabinet, DrawerRecheck $drawer): void
    {
        $audit = $this->audit($cabinet);
        abort_if($drawer->planFor($audit) === null, 404);
        $this->cabinet = $audit->cabinet_key;

        try {
            $state = $this->state($drawer);
            $this->tab = $state['drawers'][0]['n'];
            $statuses = $drawer->statuses($state['drawers'], $state['original'], $state['current'], $this->record($drawer));
            $this->guided = $this->canEdit() && in_array(DrawerRecheck::UNCHECKED, $statuses, true);
            $this->guideIndex = $this->firstToCheck($state, $drawer, 0);
        } catch (RecheckPlanException) {
            $this->guided = false;
        }
    }

    public function setTab(int $tab): void
    {
        $this->tab = $tab;
    }

    public function overview(): void
    {
        $this->guided = false;
    }

    public function startGuide(DrawerRecheck $drawer): void
    {
        $this->authorizeEdit();
        $this->guided = true;
        $this->guideIndex = $this->firstToCheck($this->state($drawer), $drawer, 0);
    }

    public function confirm(string $pbo, DrawerRecheck $drawer): void
    {
        $this->authorizeEdit();
        $drawer->confirm($this->record($drawer), [$pbo]);
    }

    public function confirmAll(DrawerRecheck $drawer): void
    {
        $this->authorizeEdit();
        $state = $this->state($drawer);
        $todo = array_keys(array_filter($drawer->statuses($state['drawers'], $state['original'], $state['current'], $this->record($drawer)), fn (string $status) => $status === DrawerRecheck::UNCHECKED));
        $drawer->confirm($this->record($drawer), $todo);
    }

    public function revert(string $pbo, DrawerRecheck $drawer): void
    {
        $this->authorizeEdit();
        $state = $this->state($drawer);
        $drawer->revert($this->record($drawer), $state['original'], $state['current'], $pbo);
    }

    public function guideConfirm(DrawerRecheck $drawer): void
    {
        $this->authorizeEdit();
        $state = $this->state($drawer);
        $name = $this->order($state)[$this->guideIndex] ?? null;

        if ($name !== null && ! DrawerRecheck::isUnnamed($name)) {
            $drawer->confirm($this->record($drawer), [$name]);
            $this->guideIndex = $this->firstToCheck($this->state($drawer), $drawer, $this->guideIndex + 1);
        }
    }

    public function guideSkip(DrawerRecheck $drawer): void
    {
        $this->guideIndex = min($this->guideIndex + 1, count($this->order($this->state($drawer))));
    }

    public function guidePrevious(): void
    {
        $this->guideIndex = max(0, $this->guideIndex - 1);
    }

    /**
     * Confirms every PBO still to check in the drawer of the one on screen.
     */
    public function guideConfirmDrawer(DrawerRecheck $drawer): void
    {
        $this->authorizeEdit();
        $state = $this->state($drawer);
        $order = $this->order($state);
        $statuses = $drawer->statuses($state['drawers'], $state['original'], $state['current'], $this->record($drawer));
        $by = DrawerPlan::byPbo($state['drawers'], $state['current']);
        $number = $by[$order[$this->guideIndex] ?? ''][0]['t'] ?? null;
        $names = array_values(array_filter($order, fn (string $name) => ($statuses[$name] ?? '') === DrawerRecheck::UNCHECKED && ($by[$name][0]['t'] ?? null) === $number));
        $drawer->confirm($this->record($drawer), $names);
        $this->guideIndex = $this->firstToCheck($this->state($drawer), $drawer, $this->guideIndex);
    }

    public function openEdit(string $pbo, DrawerRecheck $drawer): void
    {
        $this->authorizeEdit();
        $state = $this->state($drawer);
        $segments = DrawerPlan::byPbo($state['drawers'], $state['current'])[$pbo] ?? [];
        $this->edit = ['orig' => $pbo, 'name' => DrawerRecheck::isUnnamed($pbo) ? '' : $pbo, 'manual' => false, 'segs' => array_map(fn (array $s) => $this->editSegment($state, $s), $segments), 'newCable' => null];

        if ($this->edit['segs'] === []) {
            $first = $state['drawers'][0];
            $this->edit['segs'][] = $this->editSegment($state, ['t' => $this->tab ?: $first['n'], 'L' => $first['mods'][0]['L'], 'a' => 1, 'b' => min(2, $first['npos']), 'cable' => '', 'tube' => 0, 'f1' => 1, 'f2' => 2, 'pb' => '']);
        }

        $this->error = '';
        $this->clash = [];
    }

    public function openFree(int $drawerNumber, string $module, int $from, int $to, DrawerRecheck $drawer): void
    {
        $this->authorizeEdit();
        $state = $this->state($drawer);
        $cell = $state['current'][DrawerPlan::key($drawerNumber, $module, $from)] ?? ['cable' => '', 'tube' => 0];
        $this->edit = ['orig' => '', 'name' => '', 'manual' => false, 'newCable' => null, 'segs' => [$this->editSegment($state, ['t' => $drawerNumber, 'L' => $module, 'a' => $from, 'b' => $to, 'cable' => $cell['cable'], 'tube' => $cell['tube'], 'f1' => DrawerPlan::fibreOf($from), 'f2' => DrawerPlan::fibreOf($to), 'pb' => ''])]];
        $this->error = '';
        $this->clash = [];
    }

    public function cancelEdit(): void
    {
        $this->edit = null;
        $this->clash = [];
        $this->error = '';
    }

    public function editName(string $value): void
    {
        if ($this->edit === null) {
            return;
        }

        if ($value === '__manual') {
            $this->edit['manual'] = true;
            $this->edit['name'] = '';

            return;
        }

        $this->edit['manual'] = false;
        $this->edit['name'] = mb_substr(trim($value), 0, 60);
    }

    public function editManualName(string $value): void
    {
        if ($this->edit !== null) {
            $this->edit['name'] = mb_substr(trim($value), 0, 60);
        }
    }

    /**
     * @param  State  $state
     * @param  Segment  $segment
     * @return EditSegment
     */
    private function editSegment(array $state, array $segment): array
    {
        $tubes = $fibres = [];

        for ($slot = DrawerPlan::slotOf($segment['a']); $slot <= DrawerPlan::slotOf($segment['b']); $slot++) {
            $start = max($segment['a'], $slot * 12 + 1);
            $cell = $state['current'][DrawerPlan::key($segment['t'], $segment['L'], $start)] ?? null;
            $tubes[$slot] = (int) (($cell['tube'] ?? 0) ?: $segment['tube']);
            $fibres[$slot] = (int) (($cell['fibre'] ?? 0) ?: DrawerPlan::fibreOf($start));
        }

        return ['t' => $segment['t'], 'L' => $segment['L'], 'a' => $segment['a'], 'b' => $segment['b'], 'cable' => $segment['cable'], 'tubes' => $tubes, 'fibres' => $fibres, 'rings' => []];
    }

    /**
     * A field of a segment changed: drawer, module, first or last position, or cable.
     */
    public function editField(string $field, int $index, string $value, DrawerRecheck $drawer): void
    {
        $this->authorizeEdit();

        if ($this->edit === null || ! isset($this->edit['segs'][$index])) {
            return;
        }

        $state = $this->state($drawer);
        $segment = $this->edit['segs'][$index];

        switch ($field) {
            case 't':
                $segment['t'] = (int) $value;
                $found = collect($state['drawers'])->firstWhere('n', $segment['t']) ?? $state['drawers'][0];
                $segment['t'] = $found['n'];
                $segment['L'] = in_array($segment['L'], array_column($found['mods'], 'L'), true) ? $segment['L'] : $found['mods'][0]['L'];
                $segment['tubes'] = $segment['fibres'] = [];

                break;
            case 'L':
                $segment['L'] = $value;
                $segment['tubes'] = $segment['fibres'] = [];

                break;
            case 'a':
                $segment['a'] = (int) $value;
                $segment['b'] = max($segment['a'], $segment['b']);
                $segment['fibres'] = [];

                break;
            case 'b':
                $segment['b'] = (int) $value;
                $segment['a'] = min($segment['a'], $segment['b']);

                break;
            case 'cable':
                if ($value === '__new') {
                    $this->edit['newCable'] = ['i' => $index, 'cap' => 144, 'dest' => ''];
                    $segment['cable'] = '';
                } else {
                    $segment['cable'] = $this->allowedCable($state, $value);
                    $this->edit['newCable'] = null;
                }

                break;
        }

        $this->edit['segs'][$index] = $this->fitSegment($state, $segment);
    }

    public function pickTube(int $index, int $slot, int $colour): void
    {
        if ($this->edit !== null && isset($this->edit['segs'][$index]) && $colour >= 1 && $colour <= 12) {
            $segment = $this->edit['segs'][$index];
            $rings = ($segment['tubes'][$slot] ?? 0) > 0 ? DrawerPlan::ringsOfTube($segment['tubes'][$slot]) : (int) ($segment['rings'][$slot] ?? 0);
            $this->edit['segs'][$index]['tubes'][$slot] = $rings * 12 + $colour;
        }
    }

    public function setRings(int $index, int $slot, int $rings): void
    {
        if ($this->edit === null || ! isset($this->edit['segs'][$index]) || $rings < 0 || $rings > 9) {
            return;
        }

        $tube = $this->edit['segs'][$index]['tubes'][$slot] ?? 0;

        if ($tube > 0) {
            $this->edit['segs'][$index]['tubes'][$slot] = $rings * 12 + DrawerPlan::colourOfTube($tube);
        } else {
            $this->edit['segs'][$index]['rings'][$slot] = $rings;
        }
    }

    public function setFibre(int $index, int $slot, int $first): void
    {
        if ($this->edit !== null && isset($this->edit['segs'][$index]) && $first >= 1 && $first <= 24) {
            $this->edit['segs'][$index]['fibres'][$slot] = $first;
        }
    }

    /**
     * « To » of the fibres of a tube: the first fibre follows, the number of positions does not change.
     */
    public function setFibreEnd(int $index, int $slot, int $last): void
    {
        if ($this->edit === null || ! isset($this->edit['segs'][$index]) || $last < 1 || $last > 24) {
            return;
        }

        $segment = $this->edit['segs'][$index];
        $count = min($segment['b'], $slot * 12 + 12) - max($segment['a'], $slot * 12 + 1);
        $this->edit['segs'][$index]['fibres'][$slot] = max(1, $last - $count);
    }

    public function addSegment(DrawerRecheck $drawer): void
    {
        if ($this->edit === null || $this->edit['segs'] === []) {
            return;
        }

        $state = $this->state($drawer);
        $last = $this->edit['segs'][array_key_last($this->edit['segs'])];
        $npos = collect($state['drawers'])->firstWhere('n', $last['t'])['npos'] ?? 24;
        $from = min($last['b'] + 1, $npos);
        $this->edit['segs'][] = $this->editSegment($state, ['t' => $last['t'], 'L' => $last['L'], 'a' => $from, 'b' => min($last['b'] + 2, $npos), 'cable' => $last['cable'], 'tube' => 0, 'f1' => 1, 'f2' => 1, 'pb' => '']);
    }

    public function removeSegment(int $index): void
    {
        if ($this->edit !== null && count($this->edit['segs']) > 1) {
            unset($this->edit['segs'][$index]);
            $this->edit['segs'] = array_values($this->edit['segs']);
        }
    }

    public function setNewCable(string $field, string $value): void
    {
        if ($this->edit === null || $this->edit['newCable'] === null) {
            return;
        }

        if ($field === 'cap' && in_array((int) $value, DrawerPlan::CAPACITIES, true)) {
            $this->edit['newCable']['cap'] = (int) $value;
        }

        if ($field === 'dest') {
            $this->edit['newCable']['dest'] = mb_substr(mb_strtoupper(trim($value)), 0, 60);
        }
    }

    public function createCable(DrawerRecheck $drawer): void
    {
        $this->authorizeEdit();
        $new = $this->edit['newCable'] ?? null;
        $template = $this->template($this->state($drawer));

        if ($new === null || $template === null || $new['dest'] === '') {
            return;
        }

        $label = DrawerPlan::buildCable($template['prefix'], $new['cap'], $template['origin'], $new['dest']);
        $this->edit['segs'][$new['i']]['cable'] = $label;
        $this->edit['extraCables'][] = $label;
        $this->edit['newCable'] = null;
    }

    public function applyEdit(DrawerRecheck $drawer, bool $replace = false): void
    {
        $this->authorizeEdit();

        if ($this->edit === null) {
            return;
        }

        $state = $this->state($drawer);
        $result = $drawer->apply($this->record($drawer), $state['drawers'], $state['original'], $state['current'], $this->edit['orig'], $this->edit['name'], $this->edit['segs'], $replace);

        if ($result['error'] !== null) {
            $this->error = __('audit.drawer.errors.'.$result['error']);
            $this->clash = [];

            return;
        }

        if ($result['clash'] !== []) {
            $this->clash = $result['clash'];

            return;
        }

        $wasGuided = $this->guided;
        $this->cancelEdit();

        if ($wasGuided) {
            $this->guideIndex = $this->firstToCheck($this->state($drawer), $drawer, $this->guideIndex + 1);
        }
    }

    public function save(DrawerRecheck $drawer): void
    {
        $this->authorizeEdit();
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        try {
            $drawer->save($this->auditModel(), $this->plan($drawer), $this->record($drawer), $user);
        } catch (RecheckPlanException) {
            $this->addError('plan', __('audit.recheck.plan_error'));

            return;
        }

        $this->dispatch('toast', message: __('audit.drawer.saved', ['name' => $this->record($drawer)->recnok_name]));
    }

    public function leave(): void
    {
        $this->redirect(route('audits.show', ['cabinet' => $this->cabinet]), navigate: true);
    }

    public function render(DrawerRecheck $drawer): View
    {
        $title = '🗄 '.__('audit.drawer.title').' · '.$this->cabinet;
        $record = $this->record($drawer);
        $base = ['record' => $record, 'canEdit' => $this->canEdit(), 'planName' => $this->plan($drawer)->name, 'planError' => null];

        try {
            $state = $this->state($drawer);
        } catch (RecheckPlanException) {
            return view('livewire.audit.drawer-recheck-page', ['planError' => __('audit.recheck.plan_error')] + $base)->layout('components.layouts.app', ['title' => $title, 'page' => 'audits', 'focus' => true]);
        }

        $statuses = $drawer->statuses($state['drawers'], $state['original'], $state['current'], $record);
        $now = DrawerPlan::byPbo($state['drawers'], $state['current']);
        $order = $this->order($state);
        $current = collect($state['drawers'])->firstWhere('n', $this->tab) ?? $state['drawers'][0];
        $segments = DrawerPlan::segments($state['drawers'], $state['current'], $current['n']);
        $names = array_values(array_unique(array_filter(array_column($segments, 'pb'))));
        usort($names, 'strnatcasecmp');
        $needle = mb_strtolower(trim($this->search));

        return view('livewire.audit.drawer-recheck-page', $base + [
            'drawers' => $state['drawers'], 'current' => $current, 'segments' => $segments, 'statuses' => $statuses, 'now' => $now, 'order' => $order, 'names' => $names,
            'visibleNames' => array_values(array_filter($names, fn (string $name) => $needle === '' || str_contains(mb_strtolower($name), $needle))),
            'counts' => ['conform' => count(array_keys($statuses, DrawerRecheck::CONFORM, true)), 'modified' => count(array_keys($statuses, DrawerRecheck::MODIFIED, true)), 'unchecked' => count(array_keys($statuses, DrawerRecheck::UNCHECKED, true)), 'total' => count($statuses),
                'confirmable' => count(array_filter(array_keys($statuses, DrawerRecheck::UNCHECKED, true), fn (string $name) => ! DrawerRecheck::isUnnamed($name)))],
            'changed' => fn (array $segment) => $this->segmentChanged($state, $segment),
            'cables' => $this->cableList($state),
            'pbos' => $this->pboList($state),
            'destinations' => $this->destinations($state),
            'template' => $this->template($state),
            'capacities' => DrawerPlan::CAPACITIES,
            'palette' => config('audit.recheck_palette'),
            'original' => $state['original'],
            'currentCells' => $state['current'],
        ])->layout('components.layouts.app', ['title' => $title, 'page' => 'audits', 'focus' => true]);
    }

    /**
     * PBO in physical order: first drawer to last, module A onwards, positions upwards.
     *
     * @param  State  $state
     * @return list<string>
     */
    private function order(array $state): array
    {
        $order = [];

        foreach ($state['drawers'] as $drawer) {
            foreach (DrawerPlan::segments($state['drawers'], $state['current'], $drawer['n']) as $segment) {
                if ($segment['pb'] !== '' && ! in_array($segment['pb'], $order, true)) {
                    $order[] = $segment['pb'];
                }
            }
        }

        return $order;
    }

    /**
     * @param  State  $state
     */
    private function firstToCheck(array $state, DrawerRecheck $drawer, int $from): int
    {
        $order = $this->order($state);
        $statuses = $drawer->statuses($state['drawers'], $state['original'], $state['current'], $this->record($drawer));

        for ($k = 0; $k < count($order); $k++) {
            $index = ($from + $k) % max(1, count($order));

            if (($statuses[$order[$index]] ?? '') === DrawerRecheck::UNCHECKED) {
                return $index;
            }
        }

        return count($order);
    }

    /**
     * @param  State  $state
     * @param  Segment  $segment
     */
    private function segmentChanged(array $state, array $segment): bool
    {
        for ($position = $segment['a']; $position <= $segment['b']; $position++) {
            $key = DrawerPlan::key($segment['t'], $segment['L'], $position);

            if (! DrawerPlan::sameCell($state['current'][$key] ?? null, $state['original'][$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cable labels of the plan, the most fibres first, plus the ones created in the edit window.
     *
     * @param  State  $state
     * @return list<string>
     */
    private function cableList(array $state): array
    {
        $labels = collect($state['original'])->merge($state['current'])->pluck('cable')->filter()->unique()->merge($this->edit['extraCables'] ?? [])->unique()->values()->all();
        usort($labels, fn (string $a, string $b) => (DrawerPlan::parseCable($b)['cap'] ?? 0) <=> (DrawerPlan::parseCable($a)['cap'] ?? 0) ?: strnatcasecmp($a, $b));

        return $labels;
    }

    /**
     * PBO proposed: those of the plan and the active boxes of the audit that are not BPE.
     *
     * @param  State  $state
     * @return list<string>
     */
    private function pboList(array $state): array
    {
        $names = collect($state['original'])->merge($state['current'])->pluck('pb')->filter(fn (string $name) => $name !== '' && ! DrawerRecheck::isUnnamed($name))
            ->merge(AuditBox::query()->where('audit_id', $this->auditModel()->id)->whereNull('removed_at')->where('kind', '!=', 'bpe')->pluck('name'))->unique()->values()->all();
        usort($names, 'strnatcasecmp');

        return $names;
    }

    /**
     * @param  State  $state
     * @return list<string>
     */
    private function destinations(array $state): array
    {
        $names = collect($this->cableList($state))->map(fn (string $label) => DrawerPlan::parseCable($label)['dest'] ?? null)->filter()
            ->merge(AuditBox::query()->where('audit_id', $this->auditModel()->id)->whereNull('removed_at')->pluck('name'))->unique()->values()->all();
        usort($names, 'strnatcasecmp');

        return $names;
    }

    /**
     * Prefix and origin of the cables of the plan, used to write a new cable the same way.
     *
     * @param  State  $state
     * @return array{prefix: string, cap: int, origin: string, dest: string}|null
     */
    private function template(array $state): ?array
    {
        foreach ($state['original'] as $cell) {
            $parsed = DrawerPlan::parseCable($cell['cable']);

            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Only a cable of the plan or one created here is accepted.
     *
     * @param  State  $state
     */
    private function allowedCable(array $state, string $value): string
    {
        return in_array($value, $this->cableList($state), true) ? $value : '';
    }

    /**
     * Keeps the positions inside the module and the tubes of the slots that remain.
     *
     * @param  State  $state
     * @param  EditSegment  $segment
     * @return EditSegment
     */
    private function fitSegment(array $state, array $segment): array
    {
        $npos = collect($state['drawers'])->firstWhere('n', $segment['t'])['npos'] ?? 24;
        $segment['a'] = min(max(1, $segment['a']), $npos);
        $segment['b'] = min(max($segment['a'], $segment['b']), $npos);
        $tubes = $fibres = [];

        for ($slot = DrawerPlan::slotOf($segment['a']); $slot <= DrawerPlan::slotOf($segment['b']); $slot++) {
            $start = max($segment['a'], $slot * 12 + 1);
            $cell = $state['current'][DrawerPlan::key($segment['t'], $segment['L'], $start)] ?? null;
            $tubes[$slot] = (int) (($segment['tubes'][$slot] ?? 0) ?: ($cell['tube'] ?? 0));
            $fibres[$slot] = (int) (($segment['fibres'][$slot] ?? 0) ?: ($cell['fibre'] ?? 0) ?: DrawerPlan::fibreOf($start));
        }

        $segment['tubes'] = $tubes;
        $segment['fibres'] = $fibres;

        return $segment;
    }

    /**
     * @return State
     */
    private function state(DrawerRecheck $drawer): array
    {
        return $drawer->state($this->plan($drawer), $this->record($drawer));
    }

    private function authorizeEdit(): void
    {
        $this->authorize('recheck', $this->auditModel());
    }

    /**
     * Managers may recheck at any time; technicians until the audit is validated (AuditPolicy::recheck).
     */
    private function canEdit(): bool
    {
        return auth()->user()?->can('recheck', $this->auditModel()) === true;
    }

    private function audit(string $cabinet): Audit
    {
        $audit = Audit::query()->where('cabinet_key', BoxName::cabinetKey($cabinet))->firstOrFail();
        $this->authorize('work', $audit);

        return $audit;
    }

    private function auditModel(): Audit
    {
        return $this->audit($this->cabinet);
    }

    private function plan(DrawerRecheck $drawer): AuditPlan
    {
        return $drawer->planFor($this->auditModel()) ?? abort(404);
    }

    private function record(DrawerRecheck $drawer): AuditDrawerRecheck
    {
        return $drawer->record($this->auditModel());
    }
}
