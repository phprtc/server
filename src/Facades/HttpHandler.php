<?php

namespace RTC\Server\Facades;

use RTC\Contracts\Http\HttpHandlerInterface;
use RTC\Contracts\Http\KernelInterface;
use RTC\Http\Exceptions\MiddlewareException;
use RTC\Http\Request;
use RTC\Http\Router\Dispatcher;
use Swoole\Http\Request as Http1Request;
use Swoole\Http\Response as Http1Response;
use Swoole\Http2\Request as Http2Request;
use Swoole\Http2\Response as Http2Response;

class HttpHandler
{
    public function __construct(
        protected HttpHandlerInterface $handler,
        protected KernelInterface      $kernel
    )
    {
    }

    /**
     * @param Http1Request|Http2Request $swRequest
     * @param Http1Response|Http2Response $swResponse
     * @return void
     * @throws MiddlewareException
     */
    public function __invoke(Http1Request|Http2Request $swRequest, Http1Response|Http2Response $swResponse)
    {
        $httpMiddlewares = $this->kernel->getDefaultMiddlewares();

        // Dispatch http request routes if any is provided
        if ($this->handler->hasRouteCollector()) {
            $dispatchResult = Dispatcher::create($this->handler->getRouteCollector())
                ->dispatch($swRequest->getMethod(), $swRequest->server['request_uri']);
        } else {
            // Remove route dispatcher middleware, as no route collector is provided
            unset($httpMiddlewares[0]);
        }

        $request = new Request(
            $swRequest,
            $swResponse,
            $this->kernel,
            $dispatchResult ?? null
        );

        $request->initMiddleware(...$httpMiddlewares);
    }
}