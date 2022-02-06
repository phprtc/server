<?php

namespace RTC\Server\Facades;

use App\Http\Kernel;
use QuickRoute\Router\Dispatcher;
use RTC\Contracts\Http\HttpHandlerInterface;
use RTC\Http\Request;
use Swoole\Http\Request as Request1;
use Swoole\Http\Response as Response1;
use Swoole\Http2\Request as Request2;
use Swoole\Http2\Response as Response2;

class HttpHandler
{
    public function __construct(protected HttpHandlerInterface $handler)
    {
    }

    public function __invoke(Request1|Request2 $swRequest, Response1|Response2 $swResponse)
    {
        $httpMiddlewares = Kernel::getHttpMiddlewares();

        // Dispatch http request routes if any is provided
        if ($this->handler->hasRouteCollector()) {
            $dispatchResult = Dispatcher::create($this->handler->getRouteCollector())
                ->dispatch($swRequest->getMethod(), $swRequest->server['request_uri']);
        } else {
            // Remove route dispatcher middleware, as no route collector is provided
            unset($httpMiddlewares[0]);
        }

        $request = new Request($swRequest, $swResponse, $dispatchResult ?? null);
        $request->initMiddleware(...$httpMiddlewares);
    }
}