<?php

namespace RTC\Server\Facades;

use RTC\Contracts\Websocket\WebsocketHandlerInterface;
use Swoole\Http\Server;
use Swoole\WebSocket\Frame;

class WebsocketHandler
{
    public function __construct(protected WebsocketHandlerInterface $handler)
    {
    }

    public function __invoke(Server $server, Frame $frame)
    {

    }
}