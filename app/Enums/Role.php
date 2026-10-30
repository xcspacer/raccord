<?php

namespace App\Enums;

enum Role: string
{
    case Administrator = 'administrator';
    case Supervisor = 'supervisor';
    case Technician = 'technician';
    case DesignOffice = 'design_office';

    /**
     * Roles that manage the platform (planning, users' data, validations).
     *
     * @return list<self>
     */
    public static function managers(): array
    {
        return [self::Administrator, self::Supervisor];
    }

    public function label(): string
    {
        return __('roles.'.$this->value);
    }
}
