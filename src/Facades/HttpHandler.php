<?php
declare(strict_types=1);

namespace RTC\Server\Facades;

use RTC\Contracts\Http\HttpHandlerInterface;
use RTC\Contracts\Http\KernelInterface;
use RTC\Contracts\Http\RequestInterface;
use RTC\Contracts\Http\Router\DispatchResultInterface;
use RTC\Http\Exceptions\MiddlewareException;
use RTC\Http\Middlewares\ControllerExecutorMiddleware;
use RTC\Http\Request;
use RTC\Http\Router\Dispatcher;
use Swoole\Http\Request as Http1Request;
use Swoole\Http\Response as Http1Response;
use Swoole\Http2\Request as Http2Request;
use Swoole\Http2\Response as Http2Response;
use Throwable;

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
     * @throws throwable
     */
    public function __invoke(Http1Request|Http2Request $swRequest, Http1Response|Http2Response $swResponse): void
    {
        $httpMiddlewares = $this->kernel->getDefaultMiddlewares();

        // Dispatch http request routes if any is provided
        if ($this->handler->hasRouteCollector()) {
            $method = $swRequest instanceof Http1Request
                ? $swRequest->getMethod()
                : $swRequest->method;

            $dispatchResult = Dispatcher::create($this->handler->getRouteCollector())
                ->dispatch($method, $swRequest->server['request_uri']);
        } else {
            // Remove route dispatcher middleware, as no route collector is provided
            unset($httpMiddlewares[0]);
            unset($httpMiddlewares[1]);
        }

        $request = $this->makeRequest(
            $swRequest,
            $swResponse,
            $this->kernel,
            $dispatchResult ?? null
        );

        $request->initMiddleware(...$httpMiddlewares);

        if (!$request->hasRouteCollector()) {
            $this->handler->handle($request);
            return;
        }

        $request->getMiddleware()->next();
    }

    private function makeRequest(
        Http1Request|Http2Request    $request,
        Http1Response|Http2Response  $response,
        KernelInterface              $kernel,
        DispatchResultInterface|null $dispatchResult,
    ): RequestInterface
    {
        /**@phpstan-ignore-next-line * */
        return new Request($request, $response, $kernel, $dispatchResult);
    }
}