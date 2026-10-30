<?php

namespace App\Enums;

/**
 * Catalogue of the per-role access switches ("Gérer les accès"). Administrators always have every
 * feature; for the other roles a stored override wins over the catalogue default.
 */
enum Feature: string
{
    case Absences = 'absences';
    case CreateWorkSite = 'create_work_site';
    case TechnicianSpace = 'technician_space';
    case Billing = 'billing';
    case OtdrAcceptance = 'otdr_acceptance';
    case EquipmentManagement = 'equipment_management';
    case Audits = 'audits';
    case WeeklyTimesheet = 'weekly_timesheet';
    case ExpenseReports = 'expense_reports';
    case ClientControl = 'client_control';
    case MediaLibrary = 'media_library';
    case FibreCode = 'fibre_code';
    case DesignOfficeWorkflow = 'design_office_workflow';

    /**
     * Roles that have the feature when no override is stored.
     *
     * @return list<Role>
     */
    public function defaultRoles(): array
    {
        return match ($this) {
            self::Absences, self::CreateWorkSite, self::Billing, self::EquipmentManagement => [Role::Supervisor],
            self::TechnicianSpace => [Role::Technician],
            self::OtdrAcceptance, self::ExpenseReports, self::FibreCode, self::DesignOfficeWorkflow => [Role::Supervisor, Role::Technician, Role::DesignOffice],
            self::Audits, self::WeeklyTimesheet, self::MediaLibrary => [Role::Supervisor, Role::DesignOffice],
            self::ClientControl => [Role::DesignOffice],
        };
    }

    /**
     * Roles the administrator may switch the feature on or off for.
     *
     * @return list<Role>
     */
    public function configurableRoles(): array
    {
        return match ($this) {
            self::Absences, self::CreateWorkSite, self::Audits,
            self::WeeklyTimesheet, self::ClientControl, self::MediaLibrary => [Role::Supervisor, Role::DesignOffice],
            self::EquipmentManagement => [Role::Supervisor],
            self::TechnicianSpace => [Role::Technician],
            self::Billing => [Role::Supervisor, Role::Technician],
            self::OtdrAcceptance, self::ExpenseReports, self::FibreCode, self::DesignOfficeWorkflow => [Role::Supervisor, Role::Technician, Role::DesignOffice],
        };
    }

    /**
     * Installation module the feature belongs to: when that module is off, nobody has the feature.
     */
    public function module(): Module
    {
        return match ($this) {
            self::Absences, self::CreateWorkSite => Module::Planning,
            self::TechnicianSpace => Module::TechnicianSpace,
            self::Billing => Module::Billing,
            self::OtdrAcceptance => Module::OtdrAcceptance,
            self::EquipmentManagement => Module::Equipment,
            self::Audits => Module::Audits,
            self::WeeklyTimesheet => Module::DailyClockIn,
            self::ExpenseReports => Module::ExpenseReports,
            self::ClientControl => Module::ClientPortal,
            self::MediaLibrary => Module::MediaLibrary,
            self::FibreCode => Module::FibreCode,
            self::DesignOfficeWorkflow => Module::DesignOfficeWorkflow,
        };
    }

    /**
     * Section of the "Gérer les accès" page the feature is listed under.
     */
    public function group(): string
    {
        return match ($this) {
            self::Absences, self::CreateWorkSite => 'planning',
            self::TechnicianSpace, self::Billing, self::OtdrAcceptance, self::EquipmentManagement => 'field',
            self::Audits, self::WeeklyTimesheet, self::DesignOfficeWorkflow => 'audits',
            self::ExpenseReports, self::ClientControl, self::MediaLibrary, self::FibreCode => 'tools',
        };
    }

    /**
     * Administrators have every feature except the technicians' own space.
     */
    public function isGrantedToAdministrators(): bool
    {
        return $this !== self::TechnicianSpace;
    }

    public function label(): string
    {
        return __('enums.features.'.$this->value);
    }
}
