<?php

namespace Fluffy\Domain\App;

use Fluffy\Data\Connector\IConnector;
use Fluffy\Data\Connector\PostgreSQLConnector;
use Fluffy\Data\Context\DbContext;
use Fluffy\Data\Mapper\IMapper;
use Fluffy\Data\Mapper\StdMapper;
use Fluffy\Data\Repositories\BasePostgresqlRepository;
use Fluffy\Data\Repositories\MigrationRepository;
use Fluffy\Data\Repositories\SessionRepository;
use Fluffy\Data\Repositories\UserRepository;
use Fluffy\Domain\Configuration\Config;
use Fluffy\Domain\Message\HttpRequest;
use Fluffy\Domain\Message\HttpResponse;
use Fluffy\Middleware\RoutingMiddleware;
use Fluffy\Migrations\CoreMigrationsMark;
use Fluffy\Services\Auth\AuthorizationService;
use Fluffy\Services\Session\SessionService;
use Fluffy\Swoole\Connectors\SwooleRedisConnector;
use Fluffy\Swoole\Database\PostgreSQLPool;
use DotDi\DependencyInjection\IServiceProvider;
use DotDi\DependencyInjection\ServiceProviderHelper;
use ReflectionException;
use Exception;
use Swoole\Database\RedisConfig;
use Swoole\Database\RedisPool;
use Throwable;
use Fluffy\Data\Repositories\UserTokenRepository;
use Fluffy\Data\Repositories\UserVerificationCodeRepository;
use Fluffy\Domain\App\IStartUp;
use Fluffy\Domain\Viewi\ViewiFluffyBridge;
use Fluffy\Swoole\Cache\CacheManager;
use Fluffy\Swoole\RateLimit\RateLimitService;
use Fluffy\Swoole\Task\TaskManager;
use Fluffy\Swoole\Websocket\HubServer;
use Viewi\App;
use Viewi\Bridge\IViewiBridge;
use Viewi\Engine;

/** @namespaces **/
// !Do not delete the line above!

class BaseStartUp implements IStartUp
{
    protected \Viewi\App $viewiApp;
    protected Config $config;

    public function __construct(protected string $appDir)
    {
    }

    public function configureDb(IServiceProvider $serviceProvider): void { }

    function configureServices(IServiceProvider $serviceProvider): void
    {
        $serviceProvider->addSingleton(TaskManager::class);
        $serviceProvider->addSingleton(CacheManager::class);
        $serviceProvider->addSingleton(RateLimitService::class);
        $serviceProvider->addSingleton(HubServer::class);
        $this->config = new Config();
        $this->config->addArray(require($this->appDir . '/config.php'));
        $serviceProvider->setSingleton(Config::class, $this->config);
        $serviceProvider->addSingleton(IMapper::class, StdMapper::class);
        $serviceProvider->addScoped(BasePostgresqlRepository::class);
        $serviceProvider->addScoped(DbContext::class);
        $serviceProvider->addSingleton(PostgreSQLPool::class);
        $serviceProvider->addScoped(IConnector::class, PostgreSQLConnector::class);
        $serviceProvider->addScoped(SessionService::class);
        $serviceProvider->addScoped(AuthorizationService::class);

        $pool = new RedisPool((new RedisConfig)
            ->withHost('127.0.0.1')
            ->withPort(6379)
            ->withAuth('')
            ->withDbIndex($this->config->values['redisDbIndex'])
            ->withTimeout(1));
        $serviceProvider->setSingleton(RedisPool::class, $pool);

        $this->viewiApp = include $this->appDir . '/../viewi-app/viewi.php';
        $serviceProvider->setSingleton(\Viewi\App::class, $this->viewiApp);
        $serviceProvider->setSingleton(\Viewi\Router\Router::class, $this->viewiApp->router());

        $serviceProvider->addScoped(SwooleRedisConnector::class);
        $serviceProvider->addScoped(UserRepository::class);
        $serviceProvider->addScoped(SessionRepository::class);
        $serviceProvider->addScoped(MigrationRepository::class);
        $serviceProvider->addScoped(UserTokenRepository::class);
        $serviceProvider->addScoped(UserVerificationCodeRepository::class);
        /** @insert **/
        // !Do not delete the line above!
    }

    function configureMigrations(IServiceProvider $serviceProvider): void
    {
        ServiceProviderHelper::discover($serviceProvider, [CoreMigrationsMark::folder()]);
    }

    function configureInstallDependencies(IServiceProvider $serviceProvider): void
    {
        ServiceProviderHelper::discover($serviceProvider, [CoreMigrationsMark::folder()]);
    }

    function configure(BaseApp $app)
    {
        // routes handler
        $app->use(RoutingMiddleware::class);
        $serviceProvider = $app->getProvider();
        [$router, $mapper] = [$serviceProvider->get(\Viewi\Router\Router::class), $serviceProvider->get(IMapper::class)];
        RoutingMiddleware::setUpStatic($router, $mapper);
        // Viewi bridge
        /**
         * @var App $viewiApp
         */
        $viewiApp = $serviceProvider->get(App::class);
        $bridge = new ViewiFluffyBridge($serviceProvider);
        $viewiApp->factory()->add(IViewiBridge::class, static function (Engine $engine) use ($bridge) {
            return $bridge;
        });
    }

    /**
     * build before running in CLI mode
     * @return void 
     * @throws ReflectionException 
     * @throws Exception 
     */
    function buildDependencies()
    {
        echo $this->viewiApp->build();
    }
}
