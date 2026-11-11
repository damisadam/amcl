<?php

namespace App\Enums;

enum LoanAccountStatus: string
{
    use EnumHelpers;
    use StateMachine;

    case Active = 'active';
    case Overdue = 'overdue';
    case Closed = 'closed';
    case NocIssued = 'noc_issued';
    case WrittenOff = 'written_off';

    public function label(): string
    {
        return match ($this->value) {
            'noc_issued' => 'Closed – NOC Issued',
            default => \Illuminate\Support\Str::headline($this->value),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Overdue => 'red',
            self::Closed => 'blue',
            self::NocIssued => 'black',
            self::WrittenOff => 'gray',
        };
    }

    public static function transitions(): array
    {
        return [
            'active' => [self::Overdue, self::Closed, self::WrittenOff],
            'overdue' => [self::Active, self::Closed, self::WrittenOff],
            'closed' => [self::NocIssued],
            'noc_issued' => [],
            'written_off' => [],
        ];
    }
}
