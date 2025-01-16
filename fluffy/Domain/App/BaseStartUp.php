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
use Fluffy\Migrations\BaseMigrationsContext;
use Fluffy\Migrations\IMigrationsContext;
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
    protected Config $config;
    /**
     * 
     * @var IStartUp[]
     */
    protected array $startUps = [];
    /**
     * 
     * @var string[]
     */
    protected array $startUpModules = [];

    public function __construct(protected string $appDir) {}

    /**
     * 
     * @param string|IStartUp $module 
     * @return void 
     */
    public function useStartUp($startUp)
    {
        $this->startUpModules[] = $startUp;
    }

    public function configureDb(IServiceProvider $serviceProvider): void
    {
        foreach ($this->startUps as $startUp) {
            $startUp->configureDb($serviceProvider);
        }
    }

    function configureServices(IServiceProvider $serviceProvider): void
    {
        $serviceProvider->addSingleton(TaskManager::class);
        $serviceProvider->addSingleton(CacheManager::class);
        $serviceProvider->addSingleton(RateLimitService::class);
        $serviceProvider->addSingleton(HubServer::class);
        $this->config = new Config();
        $this->config->addArray(require($this->appDir . '/../configs/app.php'));
        $serviceProvider->setSingleton(Config::class, $this->config);
        $serviceProvider->addSingleton(IMapper::class, StdMapper::class);
        $serviceProvider->addScoped(BasePostgresqlRepository::class);
        $serviceProvider->addScoped(DbContext::class);
        $serviceProvider->addSingleton(PostgreSQLPool::class);
        $serviceProvider->addScoped(IConnector::class, PostgreSQLConnector::class);
        $serviceProvider->addScoped(SessionService::class);
        $serviceProvider->addScoped(AuthorizationService::class);
        $serviceProvider->addScoped(SwooleRedisConnector::class);
        $serviceProvider->addScoped(UserRepository::class);
        $serviceProvider->addScoped(SessionRepository::class);
        $serviceProvider->addScoped(MigrationRepository::class);
        $serviceProvider->addScoped(UserTokenRepository::class);
        $serviceProvider->addScoped(UserVerificationCodeRepository::class);
        /** @insert **/
        // !Do not delete the line above!
        foreach ($this->startUpModules as $startUpClass) {
            $this->startUps[] = new $startUpClass();
        }

        foreach ($this->startUps as $startUp) {
            $startUp->configureServices($serviceProvider);
        }
    }

    function configureMigrations(IServiceProvider $serviceProvider): void
    {
        ServiceProviderHelper::discover($serviceProvider, [CoreMigrationsMark::folder()]);
        $serviceProvider->addScoped(IMigrationsContext::class, BaseMigrationsContext::class);
        foreach ($this->startUps as $startUp) {
            $startUp->configureMigrations($serviceProvider);
        }
    }

    function configureInstallDependencies(IServiceProvider $serviceProvider): void
    {
        ServiceProviderHelper::discover($serviceProvider, [CoreMigrationsMark::folder()]);
        foreach ($this->startUps as $startUp) {
            $startUp->configureInstallDependencies($serviceProvider);
        }
    }

    function configure(BaseApp $app)
    {
        $serviceProvider = $app->getProvider();
        $viewiApp = $serviceProvider->get(\Viewi\App::class);
        [$router, $mapper] = [$viewiApp->router(), $serviceProvider->get(IMapper::class)];
        RoutingMiddleware::setUpStatic($router, $mapper);
        // Viewi bridge
        $bridge = new ViewiFluffyBridge($serviceProvider);
        $viewiApp->factory()->add(IViewiBridge::class, static function (Engine $engine) use ($bridge) {
            return $bridge;
        });
        foreach ($this->startUps as $startUp) {
            $startUp->configure($app);
        }
    }

    /**
     * build before running in CLI mode
     * @return void 
     * @throws ReflectionException 
     * @throws Exception 
     */
    function buildDependencies(IServiceProvider $serviceProvider)
    {
        $viewiApp = $serviceProvider->get(\Viewi\App::class);
        echo $viewiApp->build();
        foreach ($this->startUps as $startUp) {
            $startUp->buildDependencies($serviceProvider);
        }
    }
}
