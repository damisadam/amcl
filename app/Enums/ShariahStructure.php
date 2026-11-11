<?php

namespace App\Enums;

enum ShariahStructure: string
{
    use EnumHelpers;

    case Murabaha = 'murabaha';
    case Ijarah = 'ijarah';
    case DiminishingMusharakah = 'diminishing_musharakah';
    case Musharakah = 'musharakah';
    case Mudarabah = 'mudarabah';
    case Salam = 'salam';
    case HirePurchase = 'hire_purchase';
}
