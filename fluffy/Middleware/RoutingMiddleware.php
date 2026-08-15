<?php

namespace Fluffy\Middleware;

use Fluffy\Domain\Message\HttpContext;
use Fluffy\Domain\Middleware\IMiddleware;
use DotDi\DependencyInjection\Container;
use DotDi\DependencyInjection\ServiceProviderHelper;
use Exception;
use Fluffy\Data\Mapper\IMapper;
use Fluffy\Domain\Message\ResponseBuilder;
use ReflectionMethod;
use Viewi\App;
use Viewi\Components\Http\Message\Request;
use Viewi\Components\Http\Message\Response;
use Viewi\Router\ComponentRoute;
use Viewi\Router\RouteItem;
use Viewi\Router\Router;

class RoutingMiddleware implements IMiddleware
{
    private static Router $router;
    private static IMapper $mapper;

    public function __construct(private HttpContext $httpContext, private Container $container) {}

    public static function setUpStatic(Router $router, IMapper $mapper)
    {
        self::$router = $router;
        self::$mapper = $mapper;
    }

    /**
     * One request-supplied value, made safe to put in a log line.
     *
     * Every part of a 404 log line comes from the caller — the URI, the referrer, the user-agent
     * and even the forwarded IP. Control characters are stripped because a `\n` in a user-agent
     * would otherwise let a prober write its own entries into the journal, and the value is
     * bounded so a multi-kilobyte header cannot flood the log.
     */
    private function logSafe(string $value, int $max = 200): string
    {
        $value = (string) preg_replace('/[[:cntrl:]]/', ' ', $value);
        return strlen($value) > $max ? substr($value, 0, $max) . '...' : $value;
    }

    public function invoke()
    {
        $isHead = $this->httpContext->request->method === 'HEAD';
        $isOptions = $this->httpContext->request->method === 'OPTIONS';
        if ($isOptions) {
            // check allowed methods
            $allowed = ['OPTIONS'];
            foreach (['GET', 'POST', 'PUT', 'DELETE'] as $testMethod) {
                if (self::$router->resolve($this->httpContext->request->uri, $testMethod)) {
                    $allowed[] = $testMethod;
                    if ($testMethod === 'GET') {
                        $allowed[] = 'HEAD';
                    }
                }
            }
            $this->httpContext->response->status = 204;
            $this->httpContext->response->headers['Allow'] = implode(', ', $allowed);
            return;
        }

        $targetMethod = $isHead ? 'GET' : $this->httpContext->request->method;
        $match = self::$router->resolve($this->httpContext->request->uri, $targetMethod);
        if ($match === null) {
            // NOT an exception. On a public host unmatched routes are ordinary traffic: scanners
            // POST to `/` and probe for admin panels and exploit paths continuously, and a
            // mistyped URL is a user's everyday mistake. Throwing gave every one of them a stack
            // trace in the journal and answered 500 — the wrong status, and enough noise to bury
            // the errors that do matter.
            //
            // One console line instead, which systemd captures like any other app output. The
            // body is deliberately plain text: this runs before component routing, so there is no
            // page to render, and a probe should learn as little as possible from the response.
            $request = $this->httpContext->request;
            $referer = $request->headers['referer'] ?? '';
            $agent = $request->headers['user-agent'] ?? '';
            echo '[Router] 404 ' . $targetMethod . ':' . $this->logSafe($request->uri)
                . ' ip=' . $this->logSafe($request->getIp(), 45)
                . ($referer !== '' ? ' ref=' . $this->logSafe($referer) : '')
                . ($agent !== '' ? ' ua=' . $this->logSafe($agent) : '')
                . PHP_EOL;
            $this->httpContext->response->status = 404;
            if (!$isHead) {
                $this->httpContext->response->headers['Content-Type'] = 'text/plain; charset=utf-8';
                $this->httpContext->response->body = 'Not Found';
            }
            return;
        }
        $params = $this->httpContext->request->query;
        /** @var RouteItem */
        $routeItem = $match['item'];
        $action = $routeItem->action;
        $response = '';
        if (is_array($action)) {
            $instance = $this->container->serviceProvider->get($action[0]);
            if ($instance === null) {
                throw new Exception("Can't resolve an instance of {$action[0]}");
            }
            $method = $action[1];

            $r = new ReflectionMethod($action[0], $method);
            $arguments = $r->getParameters();
            // print_r($arguments);
            $inputs = [];
            $stdObject = null;
            $binaryData = false;
            $inputContent = null;
            if (count($arguments) > 0) {
                $inputContent = $this->httpContext->request->getBody();
                // $inputContent = '{"FirstName":"My name"}';
                $stdObject = json_decode($inputContent, false);
                if ($inputContent && $stdObject === null) {
                    // binary data
                    $binaryData = true;
                }
            }
            foreach ($arguments as $argument) {
                $argName = $argument->getName();
                $argumentValue = isset($match['params'][$argName])
                    ? $match['params'][$argName]
                    : (isset($params[$argName]) ? $params[$argName] : null);
                // parse json body
                if ($argumentValue === null) {
                    if ($argument->hasType() && !$argument->getType()->isBuiltin()) {
                        $typeName = $argument->getType()->getName();
                        if (class_exists($typeName) || interface_exists($typeName)) {
                            $argumentValue = $this->container->serviceProvider->get($typeName);
                            if ($argumentValue === null && $stdObject !== null) {
                                $argumentValue = self::$mapper->map($typeName, $stdObject);
                                $stdObject = null;
                            }
                            // print_r([$argumentValue, $typeName]);
                        }
                    } else if (isset($stdObject->$argName)) {
                        $argumentValue = $stdObject->$argName;
                    } else if ($argName === 'data') {
                        $argumentValue = $stdObject;
                    }
                }
                if ($argumentValue === null && $binaryData && $argName === 'data') {
                    $argumentValue = $inputContent;
                } else if ($argumentValue === null && $argument->isDefaultValueAvailable()) {
                    $argumentValue = $argument->getDefaultValue();
                }
                $inputs[] = $argumentValue;
            }
            $response = $instance->$method(...$inputs);
        } elseif ($action instanceof ComponentRoute) {
            $query = $this->httpContext->request->query;
            $queryStr = $query ? http_build_query($query) : '';
            $request = new Request($this->httpContext->request->uri . ($queryStr ? "?$queryStr" : ""), $this->httpContext->request->method);
            /**
             * @var App $viewiApp
             */
            $viewiApp = $this->container->serviceProvider->get(App::class);
            $engine = $viewiApp->engine();
            $engine->set(Container::class, $this->container);
            $response = $engine->render($action->component, $match['params'], $request);
            if ($routeItem->transformCallback !== null) {
                $response = ($routeItem->transformCallback)($response);
            }
        } elseif (is_callable($action)) {
            // TODO: match params by name
            $dependencies = ServiceProviderHelper::getDependencies($action, $this->container->serviceProvider, $match['params'] + $params);
            // var_dump($dependencies);
            $response = $action(...$dependencies); // array_values($match['params'] + $params));
            // $response = $action(...array_values($match['params'] + $params));
        } else {
            $instance = new $action();
            $response = $instance($match['params']);
        }

        if (is_string($response)) { // html
            $this->httpContext->response->headers['Content-Type'] = "text/html; charset=utf-8";
            $this->httpContext->response->body = $response;
        } elseif ($response instanceof Response) {
            $this->httpContext->response->status = $response->status;
            foreach ($response->headers as $name => $value) {
                $this->httpContext->response->headers[$name] = $value;
            }
            $this->httpContext->response->body = is_string($response->body) ? $response->body : json_encode($response->body);
        } else if ($response instanceof ResponseBuilder) {
            $this->httpContext->response->status = $response->statusCode;
            foreach ($response->headers as $name => $value) {
                $this->httpContext->response->headers[$name] = $value;
            }
            if ($response->stringify) {
                $this->httpContext->response->rawData = $response->body;
                $this->httpContext->response->body = json_encode($response->body);
            } else {
                $this->httpContext->response->body = $response->body;
            }
        } else { // json
            $this->httpContext->response->headers['Content-Type'] = "application/json; charset=utf-8";
            $this->httpContext->response->rawData = $response;
            $this->httpContext->response->body = json_encode($response);
        }
    }
}
