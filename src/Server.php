<?php

namespace RTC\Server;

use Closure;
use JetBrains\PhpStorm\Pure;
use RTC\Contracts\Http\HttpHandlerInterface;
use RTC\Contracts\Websocket\WebsocketHandlerInterface;
use RTC\Server\Exceptions\UnexpectedValueException;
use RTC\Server\Facades\HttpHandler;
use RTC\Websocket\Connection;
use Swoole\Http\Request as Http1Request;
use Swoole\Http2\Request as Http2Request;
use Swoole\Table;
use Swoole\WebSocket\Frame;

class Server
{
    protected \Swoole\Websocket\Server|\Swoole\Http\Server $server;
    protected HttpHandlerInterface $httpHandler;
    protected Kernel $httpKernel;
    protected Closure $onStartCallback;

    protected array $websocketHandlers = [];
    protected Table $connections;

    protected array $settings = [];


    public static function create(string $host, int $port): static
    {
        return new static($host, $port);
    }

    public function __construct(protected string $host, protected int $port)
    {
        $this->connections = new Table(1024);
        $this->connections->column('path', Table::TYPE_STRING, 100);
        $this->connections->create();
    }

    public function setDocumentRoot(string $path): static
    {
        $this->settings['document_root'] = $path;
        $this->settings['enable_static_handler'] = true;

        return $this;
    }

    public function setHttpHandler(HttpHandlerInterface $handler, string|Kernel $kernel): static
    {
        $this->httpHandler = $handler;

        if (is_string($kernel)) {
            $kernel = new $kernel;
        }

        if (!$kernel instanceof Kernel) {
            throw new UnexpectedValueException("Kernel must be child of {$kernel::class}");
        }

        $this->httpKernel = $kernel;

        return $this;
    }

    public function addWebsocketHandler(string $path, string|WebsocketHandlerInterface $handler): static
    {
        $this->websocketHandlers[$path] = is_object($handler) ? $handler : new $handler($this);
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

    public function push(
        int    $fd,
        string $data,
        int    $opcode = 1,
        int    $flags = SWOOLE_WEBSOCKET_FLAG_FIN
    )
    {
        if ($this->server->isEstablished($fd)) {
            $this->server->push($fd, $data, $opcode, $flags);
        }
    }

    public function exists(int $fd): bool
    {
        return $this->server->exist($fd);
    }

    public function set(array $settings): static
    {
        $this->settings = array_merge($this->settings, $settings);
        return $this;
    }

    public function findHandler(string $path): ?WebsocketHandlerInterface
    {
        foreach ($this->websocketHandlers as $handlerPath => $handler) {
            if ($path == $handlerPath) return $handler;
        }

        return null;
    }

    public function findHandlerByFD(int $fd): ?WebsocketHandlerInterface
    {
        if ($this->connections->exist($fd)) {
            return $this->websocketHandlers[$this->connections->get($fd, 'path')] ?? null;
        }

        return null;
    }

    #[Pure] public function makeConnection(int $fd): Connection
    {
        return new Connection($this, $fd);
    }


    public function run(): void
    {
        if (empty($this->websocketHandlers)) {  // Create http server if websocket is not being used
            $this->server = new \Swoole\Http\Server($this->host, $this->port);
        } else {    // Create websocket server if websocket is being used
            $this->server = new \Swoole\Websocket\Server($this->host, $this->port);
        }

        if (isset($this->httpHandler)) {
            $this->server->on('request', new HttpHandler($this->httpHandler, $this->httpKernel));
        }

        $this->server->on('start', function () {
            $cb = $this->onStartCallback;
            $cb($this->server);
        });

        // NEW CONNECTION
        $this->server->on('open', function (\Swoole\WebSocket\Server $server, Http1Request|Http2Request $request) {
            $handler = $this->findHandler($request->server['request_uri']);

            // Construct Connection Object
            $connection = $this->makeConnection($request->fd);

            // If requested ws server is not defined
            if (empty($handler)) {
                $connection->send('conn.rejected', [
                    'status' => 404,
                    'reason' => 'Handler Not Found'
                ]);

                $connection->close();

                return;
            }

            // Track The Connection
            $this->connections->set($request->fd, ['path' => $request->server['request_uri']]);

            $handler->onOpen($connection);
        });

        // CONNECTION MESSAGES
        if (isset($this->websocketHandlers)) {
            $this->server->on('message', function (\Swoole\Http\Server $server, Frame $frame) {
                // Execute Handler 'onMessage()'
                $this->findHandlerByFD($frame->fd)?->onMessage(
                    $this->makeConnection($frame->fd),
                    new \RTC\Websocket\Frame($frame)
                );
            });
        }

        // CLOSE CONNECTION
        $this->server->on('close', function (\Swoole\WebSocket\Server $server, int $fd) {
            $this->findHandlerByFD($fd)?->onClose($this->makeConnection($fd));
        });

        $this->server->set($this->settings);
        $this->server->start();
    }
}