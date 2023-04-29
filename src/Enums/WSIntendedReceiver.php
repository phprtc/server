<?php

namespace RTC\Server\Enums;

use RTC\Utils\EnumHelperTrait;

enum WSIntendedReceiver: string
{
    use EnumHelperTrait;

    case ROOM = 'room';
    case SERVER = 'server';
}
