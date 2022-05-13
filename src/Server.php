<?php

namespace RTC\Server;

use Closure;
use RTC\Contracts\Http\KernelInterface as HttpKernelInterface;
use RTC\Contracts\Server\ServerInterface;
use RTC\Contracts\Websocket\CommandInterface;
use RTC\Contracts\Websocket\ConnectionInterface;
use RTC\Contracts\Websocket\FrameInterface;
use RTC\Contracts\Websocket\KernelInterface as WSKernelInterface;
use RTC\Contracts\Websocket\WebsocketHandlerInterface;
use RTC\Server\Exceptions\UnexpectedValueException;
use RTC\Server\Facades\HttpHandler;
use RTC\Websocket\Command;
use RTC\Websocket\Connection;
use RuntimeException;
use Swoole\Http\Request as Http1Request;
use Swoole\Http2\Request as Http2Request;
use Swoole\Table;
use Swoole\WebSocket\Frame;

class Server implements ServerInterface
{
    protected \Swoole\Websocket\Server|\Swoole\Http\Server $server;
    protected HttpKernelInterface $httpKernel;
    protected WSKernelInterface $wsKernel;
    protected Closure $onStartCallback;
    protected Table $connections;

    protected bool $hasWsKernel = false;
    protected bool $hasHttpKernel = false;
    protected bool $wsHasHandlers = false;
    protected bool $httpHasHandler = false;

    /**
     * @var WebsocketHandlerInterface[]
     */
    protected array $websocketHandlers = [];

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
        $this->settings['open_websocket_close_frame'] = true;

        return $this;
    }

    /**
     * @param string|HttpKernelInterface $kernel
     * @return $this
     * @throws UnexpectedValueException
     */
    public function setHttpKernel(string|HttpKernelInterface $kernel): static
    {
        if (is_string($kernel)) {
            $kernel = new $kernel;
        }

        if (!$kernel instanceof HttpKernelInterface) {
            throw new UnexpectedValueException('Kernel must implement ' . HttpKernelInterface::class);
        }

        $this->httpKernel = $kernel;

        return $this;
    }

    /**
     * @param string|WSKernelInterface $kernel
     * @return $this
     * @throws UnexpectedValueException
     */
    public function setWebsocketKernel(string|WSKernelInterface $kernel): static
    {
        if (is_string($kernel)) {
            $kernel = new $kernel;
        }

        if (!$kernel instanceof WSKernelInterface) {
            throw new UnexpectedValueException('Kernel must implement ' . WSKernelInterface::class);
        }

        $this->wsKernel = $kernel;

        foreach ($this->wsKernel->getHandlers() as $path => $handler) {
            $this->websocketHandlers[$path] = new $handler($this);
        }

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
    ): void
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

    public function makeConnection(int $fd): ConnectionInterface
    {
        return new Connection($this, $fd);
    }

    public function makeFrame(Frame $frame): FrameInterface
    {
        return new \RTC\Websocket\Frame($frame);
    }

    public function makeCommand(FrameInterface $frame): CommandInterface
    {
        return new Command($frame);
    }

    public function run(): void
    {
        $this->hasHttpKernel = isset($this->httpKernel);
        $this->hasWsKernel = isset($this->wsKernel);
        $this->wsHasHandlers = $this->hasWsKernel && $this->wsKernel->hasHandlers();
        $this->httpHasHandler = $this->hasHttpKernel && $this->httpKernel->hasHandler();

        if (!$this->hasHttpKernel && !$this->hasWsKernel) {
            throw new RuntimeException('Please provide either websocket or http kernel');
        }

        if (!$this->hasHttpKernel && !$this->wsHasHandlers) {
            throw new RuntimeException('Please provide websocket handler');
        }

        if (
            ($this->hasWsKernel && $this->wsHasHandlers)
            && ($this->hasHttpKernel && !$this->httpHasHandler)
        ) {  // Create http server if websocket is not being used
            $this->server = new \Swoole\Http\Server($this->host, $this->port);
        } else {   // Create websocket server if websocket is being used
            $this->server = new \Swoole\Websocket\Server($this->host, $this->port);
        }

        if ($this->hasHttpKernel && $this->httpHasHandler) {
            $this->server->on('request', new HttpHandler(
                handler: $this->httpKernel->getHandler(),
                kernel: $this->httpKernel
            ));
        }

        $this->server->on('start', function () {
            if (isset($this->onStartCallback)) {
                call_user_func($this->onStartCallback, $this->server);
            }
        });

        // NEW CONNECTION
        $this->server->on('open', [$this, 'handleOnOpen']);

        // CONNECTION MESSAGES
        if ($this->wsHasHandlers) {
            $this->server->on('message', [$this, 'handleOnMessage']);
        }

        // CLOSE CONNECTION
        $this->server->on('close', function (\Swoole\WebSocket\Server|\Swoole\Http\Server $server, int $fd) {
            $this->findHandlerByFD($fd)?->onClose($this->makeConnection($fd));
        });

        // Fire HTTP handler readiness event
        if ($this->hasHttpKernel && $this->httpHasHandler) {
            $this->httpKernel->getHandler()->onReady();
        }

        // Fire WebSocket handler readiness event
        if ($this->hasWsKernel && $this->wsHasHandlers) {
            foreach ($this->websocketHandlers as $handler) {
                $handler->onReady();
            }
        }

        $this->server->set($this->settings);
        $this->server->start();
    }

    public function handleOnOpen(\Swoole\WebSocket\Server $server, Http1Request|Http2Request $request)
    {
        // Websocket
        if ($this->wsHasHandlers) {
            $handler = $this->findHandler($request->server['request_uri']);

            // Construct Connection Object
            $connection = $this->makeConnection($request->fd);

            // If requested ws server is not defined
            if (empty($handler)) {
                $connection->send('conn.rejected', [
                    'status' => 404,
                    'reason' => "No handler for route '{$request->server['request_uri']}' found."
                ]);

                $connection->close();

                return;
            }

            // Track The Connection
            $this->connections->set($request->fd, ['path' => $request->server['request_uri']]);

            $handler->onOpen($connection);
        }
    }

    public function handleOnMessage(\Swoole\Http\Server $server, Frame $frame)
    {
        if ($this->hasWsKernel) {
            $handler = $this->findHandlerByFD($frame->fd);

            if ($handler) {
                $jsonDecoded = json_decode($frame->data, true);

                $rtcConnection = $this->makeConnection($frame->fd);
                $rtcFrame = $this->makeFrame($frame);

                // Invoke 'onMessage()' method
                $handler->onMessage($rtcConnection, $rtcFrame);

                // Invoke 'onCommand()'
                if (!empty($jsonDecoded) && array_key_exists('command', $jsonDecoded)) {
                    $handler->onCommand($rtcConnection, $this->makeCommand($rtcFrame));
                }
            }
        }
    }
}