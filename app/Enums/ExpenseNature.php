<?php

namespace App\Enums;

/**
 * Nature of an expense line. The recap of the monthly report groups some of them together.
 */
enum ExpenseNature: string
{
    case Fuel = 'fuel';
    case Toll = 'toll';
    case Restaurant = 'restaurant';
    case Parking = 'parking';
    case Subscription = 'subscription';
    case OfficeSupplies = 'office_supplies';
    case Postage = 'postage';
    case EquipmentRental = 'equipment_rental';
    case VehicleRental = 'vehicle_rental';
    case SmallEquipment = 'small_equipment';
    case Phone = 'phone';
    case VehicleMaintenance = 'vehicle_maintenance';
    case Travel = 'travel';
    case MileageAllowance = 'mileage_allowance';
    case Other = 'other';

    public function label(): string
    {
        return __('enums.expense_natures.'.$this->value);
    }

    /**
     * Line of the recap this nature is summed into.
     */
    public function recapGroup(): string
    {
        return match ($this) {
            self::Parking, self::Toll, self::Travel, self::MileageAllowance => 'transport',
            default => $this->value,
        };
    }

    /**
     * Lines of the recap, in the order they are printed.
     *
     * @return list<string>
     */
    public static function recapGroups(): array
    {
        return ['restaurant', 'fuel', 'transport', 'subscription', 'office_supplies', 'postage', 'equipment_rental', 'vehicle_rental', 'small_equipment', 'phone', 'vehicle_maintenance', 'other'];
    }
}
