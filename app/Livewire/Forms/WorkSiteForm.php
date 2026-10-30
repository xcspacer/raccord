<?php

namespace App\Livewire\Forms;

use App\Enums\WorkSiteStatus;
use Illuminate\Validation\Rule;
use Livewire\Form;

class WorkSiteForm extends Form
{
    public string $agencyId = '';

    public string $client = '';

    public string $city = '';

    public string $startsOn = '';

    public string $endsOn = '';

    public string $crews = '1';

    public string $status = 'forecast';

    public string $manager = '';

    public string $notes = '';

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'agencyId' => ['required', 'integer', Rule::exists('agencies', 'id')],
            'client' => ['required', 'string', 'max:160'],
            'city' => ['required', 'string', 'max:120'],
            'startsOn' => ['required', 'date_format:Y-m-d'],
            'endsOn' => ['required', 'date_format:Y-m-d', 'after_or_equal:startsOn'],
            'crews' => ['required', 'integer', 'between:0,255'],
            'status' => ['required', Rule::enum(WorkSiteStatus::class)],
            'manager' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'agencyId' => __('work_sites.fields.agency'),
            'client' => __('work_sites.fields.client'),
            'city' => __('work_sites.fields.city'),
            'startsOn' => __('work_sites.fields.starts_on'),
            'endsOn' => __('work_sites.fields.ends_on'),
            'crews' => __('work_sites.fields.crews'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return ['endsOn.after_or_equal' => __('work_sites.date_error')];
    }
}
