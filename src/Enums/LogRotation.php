<?php

namespace RTC\Server\Enums;

enum LogRotation
{
    case SINGLE;
    case HOURLY;
    case DAILY;
    case MONTHLY;
    case EVERY_MINUTE;

    public function getValue(): int
    {
        return match ($this) {
            self::DAILY => SWOOLE_LOG_ROTATION_DAILY,
            self::HOURLY => SWOOLE_LOG_ROTATION_HOURLY,
            self::MONTHLY => SWOOLE_LOG_ROTATION_MONTHLY,
            self::EVERY_MINUTE => SWOOLE_LOG_ROTATION_EVERY_MINUTE,
            self::SINGLE => SWOOLE_LOG_ROTATION_SINGLE,
        };
    }
}
