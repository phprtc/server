<?php

namespace RTC\Server;

use Closure;
use RTC\Contracts\Http\KernelInterface as HttpKernelInterface;
use RTC\Contracts\Server\ServerInterface;
use RTC\Contracts\Websocket\ConnectionInterface;
use RTC\Contracts\Websocket\KernelInterface as WSKernelInterface;
use RTC\Contracts\Websocket\WebsocketHandlerInterface;
use RTC\Server\Exceptions\UnexpectedValueException;
use RTC\Server\Facades\HttpHandler;
use RTC\Websocket\Connection;
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


    public function run(): void
    {
        if ($this->wsKernel->hasHandlers() && !$this->httpKernel->hasHandler()) {  // Create http server if websocket is not being used
            $this->server = new \Swoole\Http\Server($this->host, $this->port);
        } else {   // Create websocket server if websocket is being used
            $this->server = new \Swoole\Websocket\Server($this->host, $this->port);
        }

        if ($this->httpKernel->hasHandler()) {
            $this->server->on('request', new HttpHandler($this->httpKernel->getHandler(), $this->httpKernel));
        }

        $this->server->on('start', function () {
            if (isset($this->onStartCallback)) {
                $callback = $this->onStartCallback;
                $callback($this->server);
            }
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
        if ($this->wsKernel->hasHandlers()) {
            $this->server->on('message', function (\Swoole\Http\Server $server, Frame $frame) {
                // Execute Handler 'onMessage()'
                $this->findHandlerByFD($frame->fd)?->onMessage(
                    $this->makeConnection($frame->fd),
                    new \RTC\Websocket\Frame($frame)
                );
            });
        }

        // CLOSE CONNECTION
        $this->server->on('close', function (\Swoole\WebSocket\Server|\Swoole\Http\Server $server, int $fd) {
            $this->findHandlerByFD($fd)?->onClose($this->makeConnection($fd));
        });

        $this->server->set($this->settings);
        $this->server->start();
    }
}