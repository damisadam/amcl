<?php

namespace App\Enums;

enum UserType: string
{
    use EnumHelpers;

    case Platform = 'platform';
    case Staff = 'staff';
    case Customer = 'customer';
    case Vendor = 'vendor';
}
