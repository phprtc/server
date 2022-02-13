<?php

namespace RTC\Server;

use RTC\Utils\InstanceCreator;

class Kernel
{
    use InstanceCreator;

    protected array $httpMiddlewares = [];

    private array $httpDefaultMiddlewares = [];

    /**
     * Specifies whether to use default http middlewares
     *
     * @var bool $useDefaultHttpMiddlewares
     */
    protected bool $useDefaultHttpMiddlewares = true;

    protected array $routeMiddlewares = [];

    /**
     * @return array
     */
    public function getHttpMiddlewares(): array
    {
        return $this->httpMiddlewares;
    }

    /**
     * @return array
     */
    public function getHttpDefaultMiddlewares(): array
    {
        return $this->useDefaultHttpMiddlewares
            ? array_merge($this->httpDefaultMiddlewares, $this->httpMiddlewares)
            : $this->httpMiddlewares;
    }

    /**
     * @return array
     */
    public function getHttpRouteMiddlewares(): array
    {
        return $this->routeMiddlewares;
    }
}