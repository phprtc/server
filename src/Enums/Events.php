<?php

namespace RTC\Server\Enums;

enum Events: string
{
    case SERVER_START = 'server_start';
    case SERVER_SHUTDOWN = 'server_shutdown';
    case SERVER_WORKER_START = 'server_worker_start';
    case SERVER_WORKER_STOP = 'server_worker_stop';
    case SERVER_WORKER_ERROR = 'server_worker_error';
    case SERVER_CONNECT = 'server_connect';
    case SERVER_RECEIVE = 'server_receive';
    case SERVER_PACKET = 'server_packet';
    case SERVER_OPEN = 'server_open';
    case SERVER_CLOSE = 'server_close';
    case SERVER_MESSAGE = 'server_message';
    case SERVER_REQUEST = 'server_request';
    case SERVER_HANDSHAKE = 'server_handshake';
    case SERVER_TASK = 'server_task';
    case SERVER_FINISH = 'server_finish';
    case SERVER_PIPE_MESSAGE = 'server_pipe_message';
    case SERVER_MANAGER_START = 'server_manager_start';
    case SERVER_MANAGER_STOP = 'server_manager_stop';
    case SERVER_BEFORE_RELOAD = 'server_before_reload';
    case SERVER_AFTER_RELOAD = 'server_after_reload';

    case WS_CONNECTION_OPENED = 'ws_connection_opened';
    case WS_CONNECTION_CLOSED = 'ws_connection_closed';

    case WS_ROOM_JOINED = 'ws_room_joined';
    case WS_ROOM_LEFT = 'ws_room_left';
    case WS_ROOM_MESSAGE = 'ws_room_message';
}
