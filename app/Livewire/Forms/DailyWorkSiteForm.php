<?php

namespace App\Livewire\Forms;

use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * One-day work site (SWAP / NRO) of an agency that plans day by day.
 */
class DailyWorkSiteForm extends Form
{
    public string $project = 'swap';

    public string $date = '';

    public string $endDate = '';

    public string $client = '';

    public string $lead = '';

    /** @var list<string> */
    public array $partners = [];

    public string $partnerToAdd = '';

    public string $city = '';

    public string $cabinetRef = '';

    public string $boxCount = '0';

    /** @var list<array{reference: string, technicianId: string, partnerCount: string, partners: list<string>}> */
    public array $boxes = [];

    /** @var list<array{lead: string, partner: string}> */
    public array $teams = [['lead' => '', 'partner' => '']];

    public string $notes = '';

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $user = Rule::exists('users', 'id')->whereNull('deleted_at');
        $audit = str_starts_with($this->project, 'audit');

        return [
            'project' => ['required', Rule::in(['swap', 'nro', 'audit', 'audit:optical', 'audit:full'])],
            'endDate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            'teams' => ['array', 'max:20'],
            'teams.*.lead' => ['nullable', 'integer', $user],
            'teams.0.lead' => [Rule::requiredIf($audit), 'nullable', 'integer', $user],
            'teams.*.partner' => ['nullable', 'integer', $user],
            'date' => ['required', 'date_format:Y-m-d'],
            'client' => ['nullable', 'string', 'max:160'],
            'lead' => [Rule::requiredIf(! $audit), 'nullable', 'integer', $user],
            'partners' => ['array', 'max:20'],
            'partners.*' => ['integer', $user],
            'city' => [Rule::requiredIf(! $audit), 'nullable', 'string', 'max:120'],
            'cabinetRef' => [Rule::requiredIf($audit), 'nullable', 'string', 'max:60', 'regex:/^[\pL\pN _\-]+$/u'],
            'boxCount' => ['required', 'integer', 'between:0,50'],
            'boxes.*.reference' => ['nullable', 'string', 'max:60'],
            'boxes.*.technicianId' => ['nullable', 'integer', $user],
            'boxes.*.partnerCount' => ['nullable', 'integer', 'between:0,10'],
            'boxes.*.partners.*' => ['nullable', 'integer', $user],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'lead.required' => __('work_sites.daily_required'),
            'cabinetRef.required' => __('work_sites.audit_required'),
            'teams.0.lead.required' => __('work_sites.audit_required'),
            'date.required' => __('work_sites.daily_required'),
            'city.required' => __('work_sites.daily_required'),
        ];
    }
}
