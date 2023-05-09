<?php
declare(strict_types=1);

namespace RTC\Server;

use Closure;
use RTC\Contracts\Exceptions\RuntimeException;
use RTC\Contracts\Exceptions\UnexpectedValueException;
use RTC\Contracts\Http\KernelInterface as HttpKernelInterface;
use RTC\Contracts\Server\ServerInterface;
use RTC\Contracts\Websocket\KernelInterface as WSKernelInterface;
use RTC\Contracts\Websocket\RoomInterface;
use RTC\Contracts\Websocket\WebsocketHandlerInterface;
use RTC\Server\Enums\LogRotation;
use RTC\Server\Facades\HttpHandler;
use RTC\Server\Websocket\WebsocketHandlerTrait;
use Swoole\Table;

class Server implements ServerInterface
{
    use WebsocketHandlerTrait;


    protected \Swoole\Websocket\Server|\Swoole\Http\Server $server;
    protected HttpKernelInterface $httpKernel;
    protected WSKernelInterface $wsKernel;
    protected Closure $onStartCallback;

    /**
     * @var static $instance
     */
    private static ServerInterface $instance;

    protected bool $hasWsKernel = false;
    protected bool $hasHttpKernel = false;
    protected bool $wsHasHandlers = false;
    protected bool $httpHasHandler = false;

    /**
     * @var WebsocketHandlerInterface[]
     */
    protected array $websocketHandlers = [];

    protected array $settings = [];
    protected Table $connections;
    protected Table $heartbeats;
    /**
     * @var RoomInterface[] $wsRooms
     */
    protected array $wsRooms = [];


    public static function create(string $host, int $port, int $size = 2048): static
    {
        return new static($host, $port, $size);
    }

    /**
     * @param string $host
     * @param int $port
     * @param int $size
     * @param int $heartbeatInterval Ping-pong interval in seconds
     * @param int $clientTimeout Client timeout in seconds
     */
    public function __construct(
        public readonly string $host,
        public readonly int    $port,
        public readonly int    $size,
        public readonly int    $heartbeatInterval = 20,
        public readonly int    $clientTimeout = 40,
    )
    {
        self::$instance = $this;

        $this->connections = new Table($this->size);
        $this->connections->column('path', Table::TYPE_STRING, 100);
        $this->connections->column('info', Table::TYPE_STRING, 1000);
        $this->connections->create();

        $this->heartbeats = new Table($this->size);
        $this->heartbeats->column('timestamp', Table::TYPE_INT, 11);
        $this->heartbeats->create();
    }

    public function daemonize(): static
    {
        $this->settings['daemonize'] = 1;
        return $this;
    }

    public function setDocumentRoot(string $path): static
    {
        $this->settings['document_root'] = $path;
        $this->settings['enable_static_handler'] = true;
        $this->settings['open_websocket_close_frame'] = true;

        return $this;
    }

    public function setPidFile(string $path): static
    {
        $this->settings['pid_file'] = $path;
        return $this;
    }

    public function setLogOption(
        string      $filePath,
        int         $level = 1,
        LogRotation $rotation = LogRotation::DAILY,
        string      $format = '%Y-%m-%d %H:%M:%S',
        bool        $withSeconds = false,
    ): static
    {
        return $this->set([
            'log_level' => $level,
            'log_file' => $filePath,
            'log_rotation' => $rotation->getValue(),
            'log_date_format' => $format,
            'log_date_with_microseconds' => $withSeconds,
        ]);
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
        if ($this->server instanceof \Swoole\WebSocket\Server && $this->server->isEstablished($fd)) {
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
        $this->server->on('open', $this->handleOnOpen(...));

        // CONNECTION MESSAGES
        if ($this->wsHasHandlers) {
            $this->server->on('message', $this->handleOnMessage(...));
        }

        // CLOSE CONNECTION
        $this->server->on('close', $this->handleOnClose(...));

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

    public static function get(): static
    {
        return self::$instance;
    }
}