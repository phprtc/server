<?php
declare(strict_types=1);

namespace RTC\Server\Facades;

use RTC\Contracts\Http\HttpException;
use RTC\Contracts\Http\HttpHandlerInterface;
use RTC\Contracts\Http\KernelInterface;
use RTC\Contracts\Http\RequestInterface;
use RTC\Contracts\Http\Router\DispatchResultInterface;
use RTC\Contracts\Server\ServerInterface;
use RTC\Http\Exceptions\MiddlewareException;
use RTC\Http\Middlewares\ControllerExecutorMiddleware;
use RTC\Http\Request;
use RTC\Http\Router\Dispatcher;
use Swoole\Http\Request as Http1Request;
use Swoole\Http\Response as Http1Response;

class HttpHandler
{
    public function __construct(
        protected ServerInterface      $server,
        protected HttpHandlerInterface $handler,
        protected KernelInterface      $kernel
    )
    {
    }

    /**
     * @param Http1Request $swRequest
     * @param Http1Response $swResponse
     * @return void
     * @throws HttpException
     */
    public function __invoke(Http1Request $swRequest, Http1Response $swResponse): void
    {
        $httpMiddlewares = $this->kernel->getDefaultMiddlewares();

        // Dispatch http request routes if any is provided
        if ($this->handler->hasRouteCollector()) {
            $method = $swRequest->getMethod();

            /**@phpstan-ignore-next-line * */
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
        Http1Request                 $request,
        Http1Response                $response,
        KernelInterface              $kernel,
        DispatchResultInterface|null $dispatchResult,
    ): RequestInterface
    {
        /**@phpstan-ignore-next-line * */
        return new Request($request, $response, $kernel, $dispatchResult, $this->server);
    }
}