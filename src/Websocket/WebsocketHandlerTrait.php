<?php

namespace RTC\Server\Websocket;

use HttpStatusCodes\StatusCode;
use RTC\Contracts\Enums\WSDisconnectMode;
use RTC\Contracts\Enums\WSEvent;
use RTC\Contracts\Enums\WSIntendedReceiver;
use RTC\Contracts\Enums\WSSenderType;
use RTC\Contracts\Exceptions\RuntimeException;
use RTC\Contracts\Websocket\ConnectionInterface;
use RTC\Contracts\Websocket\EventInterface;
use RTC\Contracts\Websocket\FrameInterface;
use RTC\Contracts\Websocket\RoomInterface;
use RTC\Contracts\Websocket\WebsocketHandlerInterface;
use RTC\Server\Enums\Events;
use RTC\Server\Exceptions\RoomNotFoundException;
use RTC\Server\Server;
use RTC\Websocket\Connection;
use RTC\Websocket\Event;
use RTC\Websocket\Room;
use Swoole\Http\Request as Http1Request;
use Swoole\Http2\Request as Http2Request;
use Swoole\Timer;
use Swoole\WebSocket\Frame;

trait WebsocketHandlerTrait
{
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
        StatusCode         $status = StatusCode::OK,
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
                'status' => $status->value,
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

    /**
     * Handle new opened ws connection
     *
     * @param \Swoole\WebSocket\Server $server
     * @param Http1Request|Http2Request $request
     * @return void
     * @throws RuntimeException
     */
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

            $connectionId = strval($request->fd);

            // Track The Connection
            $this->connections->set($connectionId, [
                'path' => $request->server['request_uri']
            ]);

            // Track Heartbeat
            $this->updateConnectionTimeout($connectionId);
            $this->trackHeartbeat($connection);
            $this->welcomeNewConnection($connection);

            // Emit event
            $this->event->emit(Events::WS_CONNECTION_OPENED->value, [$this, $connection]);

            // Invoke handler onOpen() method
            $handler->onOpen($connection);
        }
    }

    /**
     * Handle new incoming ws message
     *
     * @param \Swoole\Http\Server $server
     * @param Frame $frame
     * @return void
     * @throws RoomNotFoundException
     */
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

                    // Handle ping
                    if ($event->eventIs(WSEvent::PING->value)) {
                        $rtcConnection->send(
                            event: WSEvent::PONG->value,
                            data: ['message' => WSEvent::PONG->value]
                        );
                        return;
                    }

                    // Handle pong
                    if ($event->eventIs(WSEvent::PONG->value)) {
                        $this->updateConnectionTimeout(strval($frame->fd));
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
                        $this->dispatchRoomMessage($handler, $rtcConnection, $event);
                    }
                }
            }
        }
    }

    /**
     * Handle ws connection close
     *
     * @param \Swoole\WebSocket\Server|\Swoole\Http\Server $server
     * @param int $fd
     * @return void
     */
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
        if ($event->eventIs(WSEvent::ATTACH_INFO->value)) {
            $connection->attachInfo(strval(json_encode($event->getData())));
            $connection->send(
                event: WSEvent::INFO_ATTACHED->value,
                data: 'information saved'
            );

            return;
        }

        if ($event->eventIs(WSEvent::PONG->value)) {
            $this->updateConnectionTimeout(strval($connection->getIdentifier()));
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
            status: $statusCode,
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

    public function closeConnection(
        int|ConnectionInterface $connection,
        WSDisconnectMode        $mode,
        ?string                 $message = null
    ): void
    {
        $connection = $this->getConnection($connection);
        $closureTime = 5;

        $connection->send(
            event: WSEvent::CLOSE_CONNECTION->value,
            data: [
                'mode' => $mode->value,
                'message' => $message,
                'closure_time' => microtime(true) + $closureTime
            ]
        );

        Timer::after($closureTime, fn() => $connection->close());
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

    public function listRooms(): array
    {
        return array_keys($this->wsRooms);
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

    /**
     * @throws RoomNotFoundException
     */
    protected function dispatchRoomMessage(WebsocketHandlerInterface $handler, ConnectionInterface $connection, EventInterface $event): void
    {
        $roomId = $event->getReceiver()->getId();

        if ($roomId) {
            $room = $this->getOrCreateRoom($roomId);

            // Emit Event
            $this->event->emit(Events::WS_ROOM_MESSAGE->value, [$this, $connection, $event]);

            // Create Room
            if (WSEvent::ROOM_CREATE->value == $event->getName()) {
                return;
            }

            // Join Room
            if (WSEvent::ROOM_JOIN->value == $event->getName()) {
                $room->add($connection);
                return;
            }

            // Leave Room
            if (WSEvent::ROOM_LEAVE->value == $event->getName()) {
                $room->remove($connection);
                return;
            }

            // Room Connection List Request
            if ($event->eventIs(WSEvent::ROOM_LIST_CONNECTIONS->value)) {
                $connection->send(
                    event: WSEvent::ROOM_CONNECTIONS->value,
                    data: $this->listRoomConnections(
                        roomName: $roomId,
                        withInfo: true
                    ),
                    meta: ['room' => $roomId]
                );
                return;
            }

            // Message Room
            $handler->forwardRoomMessage(
                room: $this->getOrCreateRoom($roomId),
                connection: $connection,
                event: $event
            );
        }
    }

    /**
     * @param string $roomName
     * @param bool $withInfo
     * @return array
     * @throws RoomNotFoundException
     */
    protected function listRoomConnections(string $roomName, bool $withInfo): array
    {
        if ($this->roomExists($roomName)) {
            return $this->getRoom($roomName)->listConnections($withInfo);
        }

        return [];
    }

    protected function trackHeartbeat(ConnectionInterface $connection): void
    {
        Timer::tick($this->heartbeatInterval * 1000, function (int $timerId) use ($connection) {
            $connectionId = strval($connection->getIdentifier());
            $clientTimeout = $this->heartbeats->get($connectionId, 'timeout');

            // Close connections that failed to respond within specified clientTimeout value
            if (time() >= $clientTimeout) {
                $connection->send(
                    event: WSEvent::PING->value,
                    data: ['message' => 'routine pulse check'],
                );

                $this->closeConnection(
                    connection: $connection,
                    mode: WSDisconnectMode::TIMEOUT,
                    message: 'Failed to reply sent pings'
                );

                Timer::clear($timerId);
                return;
            }

            // Send ping
            $connection->send(
                event: WSEvent::PING->value,
                data: ['message' => WSEvent::PONG->value]
            );
        });
    }

    protected function updateConnectionTimeout(string $connectionId): void
    {
        $this->heartbeats->set($connectionId, [
            'timeout' => time() + $this->clientTimeout
        ]);
    }

    /**
     * Send payload to newly connected client
     *
     * @param ConnectionInterface $connection
     * @return void
     */
    protected function welcomeNewConnection(ConnectionInterface $connection): void
    {
        $connection->send(
            event: WSEvent::WELCOME->value,
            data: [
                'sid' => $connection->getIdentifier(),
                'ping_interval' => $this->heartbeatInterval,
                'client_timeout' => $this->clientTimeout
            ]
        );
    }
}