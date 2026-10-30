<?php

namespace App\Enums;

/**
 * Optional platform modules, each switched on or off per installation in the platform settings.
 */
enum Module: string
{
    case Planning = 'planning';
    case TechnicianSpace = 'technician_space';
    case DailyClockIn = 'daily_clock_in';
    case ExpenseReports = 'expense_reports';
    case Equipment = 'equipment';
    case Audits = 'audits';
    case ClientPortal = 'client_portal';
    case MediaLibrary = 'media_library';
    case Billing = 'billing';
    case OtdrAcceptance = 'otdr_acceptance';
    case FibreCode = 'fibre_code';
    case DesignOfficeWorkflow = 'design_office_workflow';

    /**
     * Modules that must be enabled for this one to work.
     *
     * @return list<self>
     */
    public function requires(): array
    {
        return match ($this) {
            self::ClientPortal, self::DesignOfficeWorkflow => [self::Audits],
            default => [],
        };
    }

    public function label(): string
    {
        return __('modules.'.$this->value);
    }
}
