<?php

namespace RTC\Server\Enums;

use RTC\Utils\EnumHelperTrait;

enum WSRoomTerm: string
{
    use EnumHelperTrait;

    case JOIN = 'join';
    case MESSAGE = 'message';
    case LEAVE = 'leave';
    case REMOVE = 'remove';
    case CREATE = 'create';
}
