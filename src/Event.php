<?php

namespace RTC\Server;

use Evenement\EventEmitter;

class Event extends EventEmitter
{
    public function emit($event, array $arguments = []): void
    {
        go(fn() => parent::emit($event, $arguments));
    }
}