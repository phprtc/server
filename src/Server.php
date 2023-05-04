<?php
declare(strict_types=1);

namespace RTC\Server;

use Closure;
use HttpStatusCodes\StatusCode;
use RTC\Contracts\Enums\WSEvent;
use RTC\Contracts\Enums\WSIntendedReceiver;
use RTC\Contracts\Enums\WSSenderType;
use RTC\Contracts\Exceptions\RuntimeException;
use RTC\Contracts\Exceptions\UnexpectedValueException;
use RTC\Contracts\Http\KernelInterface as HttpKernelInterface;
use RTC\Contracts\Server\ServerInterface;
use RTC\Contracts\Websocket\ConnectionInterface;
use RTC\Contracts\Websocket\EventInterface;
use RTC\Contracts\Websocket\FrameInterface;
use RTC\Contracts\Websocket\KernelInterface as WSKernelInterface;
use RTC\Contracts\Websocket\RoomInterface;
use RTC\Contracts\Websocket\WebsocketHandlerInterface;
use RTC\Server\Enums\LogRotation;
use RTC\Server\Exceptions\RoomNotFoundException;
use RTC\Server\Facades\HttpHandler;
use RTC\Websocket\Connection;
use RTC\Websocket\Event;
use RTC\Websocket\Room;
use Swoole\Http\Request as Http1Request;
use Swoole\Http2\Request as Http2Request;
use Swoole\Table;
use Swoole\Timer;
use Swoole\WebSocket\Frame;

class Server implements ServerInterface
{
    protected \Swoole\Websocket\Server|\Swoole\Http\Server $server;
    protected HttpKernelInterface $httpKernel;
    protected WSKernelInterface $wsKernel;
    protected Closure $onStartCallback;
    protected Table $connections;

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

    /**
     * @var RoomInterface[] $wsRooms
     */
    protected array $wsRooms = [];


    public static function create(string $host, int $port, int $size = 2048): static
    {
        return new static($host, $port, $size);
    }

    public function __construct(
        public readonly string $host,
        public readonly int    $port,
        public readonly int    $size
    )
    {
        self::$instance = $this;

        $this->connections = new Table(1024);
        $this->connections->column('path', Table::TYPE_STRING, 100);
        $this->connections->column('info', Table::TYPE_STRING, 1000);
        $this->connections->create();
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

    public function findHandlerByFD(int $fd): ?WebsocketHandlerInterface
    {
        if ($this->connections->exist(strval($fd))) {
            return $this->websocketHandlers[$this->connections->get(strval($fd), 'path')] ?? null;
        }

        return null;
    }

    public function makeConnection(int $fd): ConnectionInterface
    {
        /**@phpstan-ignore-next-line * */
        return new Connection($fd);
    }

    public function makeFrame(Frame $frame): FrameInterface
    {
        /**@phpstan-ignore-next-line * */
        return new \RTC\Websocket\Frame($frame);
    }

    public function makeEvent(FrameInterface $frame): EventInterface
    {
        /**@phpstan-ignore-next-line * */
        return new Event($frame);
    }

    /**
     * Attach information to a connection
     *
     * @param ConnectionInterface|int $connection
     * @param string $info
     * @return $this
     */
    public function attachConnectionInfo(ConnectionInterface|int $connection, string $info): static
    {
        $connId = $this->getConnectionId($connection);
        $data = $this->connections->get($connId);
        $data['info'] = $info;
        $this->connections->set($connId, $data);
        return $this;
    }

    /**
     * Get attached connection information
     *
     * @param ConnectionInterface|int $connection
     * @return string|null
     */
    public function getConnectionInfo(ConnectionInterface|int $connection): ?string
    {
        return $this->connections->get($this->getConnectionId($connection), 'info');
    }

    public function sendWSMessage(
        int                $fd,
        string             $event,
        mixed              $data,
        WSSenderType       $senderType,
        string             $senderId,
        WSIntendedReceiver $receiverType,
        string             $receiverId,
        array              $meta = [],
        int                $opcode = 1,
        int                $flags = SWOOLE_WEBSOCKET_FLAG_FIN
    ): void
    {
        $this->push(
            fd: $fd,
            data: strval(json_encode([
                'event' => $event,
                'data' => $data,
                'meta' => $meta,
                'time' => microtime(true),
                'sender' => [
                    'type' => $senderType->value,
                    'id' => $senderId,
                    'info' => $senderType == WSSenderType::USER
                        ? $this->getConnectionInfo(intval($senderId))
                        : null
                ],
                'receiver' => [
                    'type' => $receiverType->value,
                    'id' => $receiverId
                ]
            ])),
            opcode: $opcode,
            flags: $flags
        );
    }

    public function createRoom(string $name, int $size): RoomInterface
    {
        /**
         * @var RoomInterface $room
         * @phpstan-ignore-next-line
         */
        $room = new Room($this, $name, $size);
        $this->attachRoom($room);

        return $room;
    }

    public function attachRoom(RoomInterface $room): static
    {
        $this->wsRooms[$room->getName()] = $room;
        return $this;
    }

    public function roomExists(string $name): bool
    {
        return array_key_exists($name, $this->wsRooms);
    }

    /**
     * @param string $name
     * @return RoomInterface
     * @throws RoomNotFoundException
     */
    public function getRoom(string $name): RoomInterface
    {
        if ($this->roomExists($name)) {
            return $this->wsRooms[$name];
        }

        throw new RoomNotFoundException("Room with name \"$name\" not found");
    }

    public function getOrCreateRoom(string $name): RoomInterface
    {
        return $this->roomExists($name)
            ? $this->getRoom($name)
            : $this->createRoom($name, $this->size);
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

    protected function handleOnOpen(\Swoole\WebSocket\Server $server, Http1Request|Http2Request $request): void
    {
        if ($request instanceof Http2Request) {
            throw new RuntimeException('Http2 is not supported yet.');
        }

        // Websocket
        if ($this->wsHasHandlers) {
            $handler = $this->findHandler($request->server['request_uri']);

            // Construct Connection Object
            $connection = $this->makeConnection($request->fd);

            // If requested ws server is not defined
            if (empty($handler)) {
                $this->rejectConnection(
                    connection: $connection,
                    reason: "No handler for route '{$request->server['request_uri']}' found.",
                    statusCode: StatusCode::NOT_FOUND,
                    meta: ['path' => $request->server['request_uri']]
                );
                return;
            }

            // Track The Connection
            $this->connections->set(strval($request->fd), ['path' => $request->server['request_uri']]);

            $handler->onOpen($connection);
        }
    }

    protected function handleOnMessage(\Swoole\Http\Server $server, Frame $frame): void
    {
        if ($this->hasWsKernel) {
            $handler = $this->findHandlerByFD($frame->fd);

            if ($handler) {
                $jsonDecoded = json_decode($frame->data, true);

                $rtcConnection = $this->makeConnection($frame->fd);
                $rtcFrame = $this->makeFrame($frame);

                // Invoke 'onMessage()' method
                $handler->onMessage($rtcConnection, $rtcFrame);

                // Invoke 'onEvent()'
                if (!empty($jsonDecoded) && array_key_exists('event', $jsonDecoded)) {
                    $event = $this->makeEvent($rtcFrame);
                    $handler->onEvent($rtcConnection, $event);

                    // Handle ping-pong
                    if ($event->eventIs(WSEvent::PING->value)) {
                        $rtcConnection->send(
                            event: WSEvent::PONG->value,
                            data: ['message' => WSEvent::PONG->value]
                        );
                        return;
                    }

                    $receiver = $event->getReceiver();

                    if (!$receiver->isValid()) {
                        $rtcConnection->send(
                            event: WSEvent::EVENT_REJECTED->value,
                            data: 'invalid event receiver',
                            meta: [
                                'name' => $event->getName(),
                                'data' => $event->getData()
                            ],
                            status: StatusCode::BAD_REQUEST,
                        );
                        return;
                    }

                    if (WSIntendedReceiver::SERVER->value == $receiver->getType()) {
                        $this->dispatchServerMessage($rtcConnection, $event);
                    }

                    if (WSIntendedReceiver::ROOM->value == $receiver->getType()) {
                        $this->dispatchRoomMessage($rtcConnection, $event);
                    }
                }
            }
        }
    }

    protected function handleOnClose(\Swoole\WebSocket\Server|\Swoole\Http\Server $server, int $fd): void
    {
        $connId = strval($fd);
        if ($this->connections->exist($connId)) {
            $connection = $this->makeConnection($fd);
            $this->findHandlerByFD($fd)?->onClose($connection);

            // Remove connection from rooms
            foreach ($this->wsRooms as $room) {
                $room->remove($connection);
            }

            // Remove connection from tracking list
            $this->connections->delete(strval($fd));
        }
    }

    protected function dispatchServerMessage(ConnectionInterface $connection, EventInterface $event): void
    {
        // Attach Information To Client
        if (WSEvent::ATTACH_INFO->value == $event->getName()) {
            $connection->attachInfo(strval(json_encode($event->getData())));
            $connection->send(
                event: WSEvent::INFO_ATTACHED->value,
                data: 'information saved'
            );

            return;
        }
    }

    protected function dispatchRoomMessage(ConnectionInterface $connection, EventInterface $event): void
    {
        $roomId = $event->getReceiver()->getId();

        if ($roomId) {
            // Create Room
            if (WSEvent::CREATE->value == $event->getName()) {
                $this->createRoom($roomId, $this->size);
                return;
            }

            // Join Room
            if (WSEvent::JOIN->value == $event->getName()) {
                $this->getOrCreateRoom($roomId)->add($connection);
                return;
            }

            // Leave Room
            if (WSEvent::LEAVE->value == $event->getName()) {
                $this->getOrCreateRoom($roomId)->remove($connection);
                return;
            }

            // Message Room
            foreach ($this->wsRooms as $room) {
                if ($room->getName() == $roomId) {
                    $room->sendAsClient(
                        connection: $connection,
                        event: $event->getName(),
                        message: $event->getData(),
                    );
                }
            }
        }
    }

    protected function rejectConnection(ConnectionInterface $connection, string $reason, StatusCode $statusCode, array $meta = []): void
    {
        $connection->send(
            event: 'conn.rejected',
            data: [
                'status' => $statusCode->value,
                'reason' => $reason,
            ],
            meta: $meta,
        );

        // Let user receive rejection message first before disconnecting
        Timer::after(100, fn() => $connection->close());
    }

    protected function getConnectionId(int|ConnectionInterface $connection): string
    {
        return strval(is_int($connection) ? $connection : $connection->getIdentifier());
    }

    protected function getConnection(int|ConnectionInterface $connection): ConnectionInterface
    {
        if (is_int($connection)) {
            return Server::get()->makeConnection($connection);
        }

        return $connection;
    }

    public static function get(): static
    {
        return self::$instance;
    }
}