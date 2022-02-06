<?php

namespace RTC\Server;

use Closure;
use RTC\Contracts\Http\HttpHandlerInterface;
use RTC\Contracts\Websocket\WebsocketHandlerInterface;
use RTC\Server\Facades\HttpHandler;
use RTC\Server\Facades\WebsocketHandler;

class Server
{
    protected \Swoole\Http\Server $server;
    protected HttpHandlerInterface $httpHandler;
    protected WebsocketHandlerInterface $websocketHandler;
    protected Closure $onStartCallback;


    public static function create(string $host, int $port): static
    {
        return new Server($host, $port);
    }

    public function __construct(protected string $host, protected int $port)
    {
        $this->server = new \Swoole\Http\Server($this->host, $this->port);
    }

    public function setHttpHandler(HttpHandlerInterface $handler): static
    {
        $this->httpHandler = $handler;
        return $this;
    }

    public function setWebsocketHandler(WebsocketHandlerInterface $handler): static
    {
        $this->websocketHandler = $handler;
        return $this;
    }

    public function getServer(): \Swoole\Http\Server
    {
        return $this->server;
    }

    public function onStart(Closure $callback): static
    {
        $this->onStartCallback = $callback;
        return $this;
    }

    public function run(): void
    {
        if (isset($this->httpHandler)) {
            $this->server->on('request', new HttpHandler($this->httpHandler));
        }

        if (isset($this->websocketHandler)) {
            $this->server->on('message', new WebsocketHandler($this->websocketHandler));
        }

        $this->server->on('start', function () {
            $cb = $this->onStartCallback;
            $cb($this->server);
        });

        $this->server->start();
    }

    public function set(array $settings): static
    {
        $this->server->set(...$settings);
        return $this;
    }
}