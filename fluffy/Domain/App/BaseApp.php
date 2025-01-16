<?php

namespace Fluffy\Domain\App;

use AppServer;
use DotDi\DependencyInjection\IServiceProvider;
use DotDi\DependencyInjection\ServiceProvider;
use Exception;
use Fluffy\Domain\CronTab\CronTab;
use Fluffy\Domain\Hubs\HubRunner;
use Fluffy\Domain\Hubs\Hubs;
use Fluffy\Domain\Message\FpmHttpRequest;
use Fluffy\Domain\Message\FpmHttpResponse;
use Fluffy\Domain\Message\HttpContext;
use Fluffy\Domain\Message\HttpRequest;
use Fluffy\Domain\Message\HttpResponse;
use Fluffy\Domain\Message\SocketMessage;
use Fluffy\Migrations\BaseMigrationsContext;
use Fluffy\Migrations\IMigrationsContext;
use Fluffy\Swoole\Task\TaskManager;
use Fluffy\Swoole\Task\TaskMessage;
use Throwable;

abstract class BaseApp
{
    public array $middleware = [];
    public int $middlewareCount = 0;
    private IServiceProvider $serviceProvider;
    private TaskManager $taskManager;

    public function __construct(private IStartUp $startUp)
    {
        $this->serviceProvider = new ServiceProvider();
    }

    abstract function getViewiApp(): \Viewi\App;

    /**
     * Prepare application instance for long lifetime cycle (ReactPHP, Swoole)
     * @return void 
     */
    function build()
    {
        $this->setUp();
        $this->startUp->buildDependencies($this->serviceProvider);
    }

    /**
     * Run migrations
     * @return void 
     */
    function runMigrations()
    {
        $this->setUp();
        $this->startUp->configureMigrations($this->serviceProvider);
        // create scope
        $scope = $this->serviceProvider->createScope();
        try {
            // create request and http context
            /** @var IMigrationsContext[] $migrationsContexts */
            $migrationsContexts = $scope->serviceProvider->getAll(IMigrationsContext::class);
            foreach ($migrationsContexts as $migrationsContext) {
                $migrationsContext->run();
            }
        } finally {
            $scope->dispose();
        }
    }

    function rollbackMigration(string $migration)
    {
        $this->setUp();
        $this->startUp->configureMigrations($this->serviceProvider);
        // create scope
        $scope = $this->serviceProvider->createScope();
        try {
            // create request and http context
            /** @var BaseMigrationsContext $migrationsContext */
            $migrationsContext = $scope->serviceProvider->get(BaseMigrationsContext::class);
            $migrationsContext->rollback($migration);
        } finally {
            $scope->dispose();
        }
    }

    /**
     * First time installation
     * @return void 
     */
    function install()
    {
        $this->setUp();
        $this->startUp->configureInstallDependencies($this->serviceProvider);
        // create scope
        $scope = $this->serviceProvider->createScope();
        try {
            // create request and http context
            /** @var MigrationsContext $migrationsContext */
            $migrationsContext = $scope->serviceProvider->get(BaseMigrationsContext::class);
            $migrationsContext->run();
        } finally {
            $scope->dispose();
        }
    }

    /**
     * 
     * @return IServiceProvider 
     */
    function getProvider(): IServiceProvider
    {
        return $this->serviceProvider;
    }

    /**
     * 
     * @param callable(IServiceProvider $services):void $registerCallback 
     * @return void 
     */
    function registerServices(callable $registerCallback)
    {
        $registerCallback($this->serviceProvider);
    }

    function setAppDependencies(AppServer $appServer)
    {
        $this->serviceProvider->setSingleton(AppServer::class, $appServer);
    }

    function setUp()
    {
        // start up and configure
        $viewiApp = $this->getViewiApp();
        if (!isset($viewiApp)) {
            throw new Exception("Viewi application is not set: viewiApp.");
        }
        $this->serviceProvider->setSingleton(\Viewi\App::class, $viewiApp);
        $this->serviceProvider->setSingleton(\Viewi\Router\Router::class, $viewiApp->router());
        $this->startUp->configureServices($this->serviceProvider);
        $this->startUp->configureDb($this->serviceProvider);
        $this->startUp->configure($this);
        $this->middlewareCount = count($this->middleware);
    }

    function run()
    {
        $this->setUp();
        // listen to request events
        // get request or listen to the loop events (SAPI, ReactPHP, Swoole)
        $request = new FpmHttpRequest(
            $_SERVER['REQUEST_METHOD'],
            isset($_SERVER['REDIRECT_URL']) ? $_SERVER['REDIRECT_URL'] : preg_replace('/\?.*/', '', $_SERVER['REQUEST_URI']),
            getallheaders(),
            $_GET
        );
        // handle
        $httpContext = new HttpContext($request, new FpmHttpResponse());
        $this->handle($httpContext);
    }

    function handle(HttpContext $httpContext)
    {
        // create scope
        $scope = $this->serviceProvider->createScope();
        try {
            // create request and http context
            $scope->serviceProvider->set(HttpRequest::class, $httpContext->request);
            $scope->serviceProvider->set(HttpResponse::class, $httpContext->response);
            $scope->serviceProvider->set(HttpContext::class, $httpContext);
            $requestDelegate = new RequestDelegate($this, $scope);
            $scope->serviceProvider->set(RequestDelegate::class, $requestDelegate);
            // run middleware(s)
            $requestDelegate();
            // end response
            $httpContext->response->end();
        } finally {
            // dispose scope
            // unset($requestDelegate);
            $scope->dispose();
            // unset($scope);
        }
    }

    function onSocketMessage(string $data, int $fd, int $opcode, bool $finish, int $flags)
    {
        if ($data) {
            /** @var SocketMessage $message  */
            $message = @json_decode($data, false);
            if ($message && $message->route) {
                $hubRoute = Hubs::resolve($message->route);
                if ($hubRoute === null) {
                    echo "[Server] Hub error: can't resolve hub route '$message->route'" . PHP_EOL;
                    return;
                }
                $scope = $this->serviceProvider->createScope();
                try {
                    HubRunner::run($hubRoute, $message->data, $scope->serviceProvider);
                } catch (Throwable $t) {
                    echo '[Server] Hub error.' . PHP_EOL;
                    echo $t->__toString() . PHP_EOL;
                } finally {
                    // dispose scope
                    // unset($requestDelegate);
                    $scope->dispose();
                    // unset($scope);
                }
            }
        }
    }

    function startCrontab()
    {
        echo '[Server] startCrontab.' . PHP_EOL;
        try {
            $this->taskManager = $this->serviceProvider->get(TaskManager::class);
            $this->taskManager->resetCronTable();
            $jobs = CronTab::getJobs();
            foreach ($jobs as $job) {
                $this->taskManager->scheduleCronJob($job);
            }
        } catch (Throwable $t) {
            echo '[Server] Crontab error.' . PHP_EOL;
            echo $t->__toString() . PHP_EOL;
        }
    }

    function task(string $class, string $method, array $params)
    {
        // create scope
        $scope = $this->serviceProvider->createScope();
        try {
            $taskInstance = $scope->serviceProvider->get($class);
            $taskInstance->{$method}(...$params);
        } catch (Throwable $t) {
            echo '[Server] Task error.' . PHP_EOL;
            echo $t->__toString() . PHP_EOL;
        } finally {
            // dispose scope
            // unset($requestDelegate);
            $scope->dispose();
            // unset($scope);
        }
    }

    /**
     * On message from other worker/thread/process
     * @return void 
     */
    function onPipeMessage(TaskMessage $message)
    {
        $this->taskManager->processMessage($message);
    }

    public function use($middleware)
    {
        $this->middleware[] = $middleware;
    }
}
