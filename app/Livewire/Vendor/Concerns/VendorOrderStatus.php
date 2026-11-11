<?php

namespace App\Livewire\Vendor\Concerns;

use App\Enums\ApplicationStatus;

/**
 * What a vendor may see about a financing order (M19): only "In progress", "Approved" and
 * "Disbursed (paid)". Customer outcomes such as rejected / withdrawn / cancelled are private to the
 * customer and the institution, so those orders are hidden from the vendor portal entirely.
 */
final class VendorOrderStatus
{
    public const STAGES = [
        'in_progress' => ['draft', 'submitted', 'under_review', 'pending_approval', 'returned'],
        'approved' => ['approved', 'kfs_acknowledged', 'cooling_off', 'ready_for_disbursement', 'disbursement_in_progress'],
        'disbursed' => ['disbursed'],
    ];

    public const LABELS = ['in_progress' => 'In progress', 'approved' => 'Approved', 'disbursed' => 'Disbursed (paid)'];

    public const TONES = ['in_progress' => 'amber', 'approved' => 'blue', 'disbursed' => 'green'];

    /** @return list<string> */
    public static function visible(): array
    {
        return array_merge(...array_values(self::STAGES));
    }

    public static function stage(ApplicationStatus|string $status): ?string
    {
        $v = $status instanceof ApplicationStatus ? $status->value : $status;
        foreach (self::STAGES as $stage => $list) {
            if (in_array($v, $list, true)) {
                return $stage;
            }
        }

        return null;
    }

    public static function label(ApplicationStatus|string $status): string
    {
        return __(self::LABELS[self::stage($status)] ?? 'Closed');
    }

    public static function tone(ApplicationStatus|string $status): string
    {
        return self::TONES[self::stage($status)] ?? 'gray';
    }
}
