<?php

namespace TorstenDittmann\Gustav;

use ReflectionClass;
use ReflectionMethod;
use Sabre\HTTP\Response;
use Sabre\HTTP\Sapi;
use TorstenDittmann\Gustav\Attributes\Param;
use TorstenDittmann\Gustav\Attributes\Route;

class Application
{
    protected array $routes = [];
    protected array $controllers = [];

    public function register(string $class): self
    {
        $instance = new $class();
        $reflector = new ReflectionClass($class);

        $this->controllers[$class] = $instance;

        $this->addMethods($reflector);

        return $this;
    }

    protected function addMethods(ReflectionClass $reflector): void
    {
        foreach ($reflector->getMethods() as $method) {
            $routes = $method->getAttributes(Route::class);

            foreach ($routes as $route) {
                /**
                 * @var Route $instance
                 */
                $instance = $route->newInstance();
                $instance
                    ->setClass($reflector->getName())
                    ->setFunction($method->getName());

                $this->addParameters($method, $instance);
                Router::addRoute($instance);
            }
        }
    }

    protected function addParameters(ReflectionMethod $method, Route $route): void
    {
        foreach ($method->getParameters() as $parameter) {
            foreach ($parameter->getAttributes(Param::class) as $attribute) {
                /**
                 * @var Param $instance
                 */
                $instance = $attribute->newInstance();
                $instance
                    ->setParameter($parameter->getName())
                    ->setRequired(!$parameter->isOptional());
                $route->addParam($instance->getParameter(), $instance);
            }
        }
    }

    public function start()
    {
        $request = Sapi::getRequest();
        $response = new Response();

        try {
            $route = Router::match(Method::fromRequest($request), $request->getPath());
            $controller = $this->controllers[$route->getClass()];
            $params = $route->generateParams($request);
            $payload = $controller->{$route->getFunction()}(...$params);
            $body = \json_encode($payload);
            $response->setStatus(200);
            $response->setBody($body);
        } catch (\Throwable $th) {
            $body = \json_encode([
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'code' => $th->getCode(),
                'trace' => $th->getTrace(),
            ]);
            $response->setStatus(500);
            $response->setBody($body);
        } finally {
            $response->setHeader('Content-Type', 'application/json');
            Sapi::sendResponse($response);
        }
    }
}
