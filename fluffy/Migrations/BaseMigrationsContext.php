<?php

namespace Fluffy\Migrations;

use Fluffy\Migrations\Auth\SessionMigration;
use Fluffy\Migrations\Auth\UsersMigration;
use DotDi\DependencyInjection\Container;
use Fluffy\Migrations\Auth\UserTokenMigration;
use Fluffy\Migrations\Auth\UserTokenDropTokenMigration;
use Fluffy\Migrations\Auth\UserVerificationCodeMigration;
use Fluffy\Migrations\Auth\UserPermissionsMigration;
use Fluffy\Migrations\InstallMigration;
use Fluffy\Migrations\Settings\SettingMigration;

class BaseMigrationsContext implements IMigrationsContext
{
    /**
     * When not null, runMigration() collects class names here instead of
     * applying them — used by status() to enumerate the registered migrations.
     * @var string[]|null
     */
    private ?array $collected = null;

    public function __construct(protected Container $container) {}

    public function run()
    {
        $this->runMigration(InstallMigration::class);
        $this->runMigration(UsersMigration::class);
        $this->runMigration(UserPermissionsMigration::class);
        $this->runMigration(SessionMigration::class);
        $this->runMigration(UserTokenMigration::class);
        $this->runMigration(UserTokenDropTokenMigration::class);
        $this->runMigration(UserVerificationCodeMigration::class);
        $this->runMigration(SettingMigration::class);
    }

    public function runMigration(string $type)
    {
        if ($this->collected !== null) {
            $this->collected[] = $type;
            return;
        }
        /** @var BaseMigration $migration */
        $migration = $this->container->serviceProvider->get($type);
        $migration->runUp();
    }

    public function status(): array
    {
        // Run in collect mode so run()'s runMigration() calls only record names.
        $this->collected = [];
        try {
            $this->run();
            $names = $this->collected;
        } finally {
            $this->collected = null;
        }
        $result = [];
        foreach ($names as $type) {
            /** @var BaseMigration $migration */
            $migration = $this->container->serviceProvider->get($type);
            $result[] = ['name' => $type, 'applied' => $migration->isMigrated($migration->migrationName())];
        }
        return $result;
    }

    public function rollback(string $type)
    {
        /** @var BaseMigration $migration */
        $migration = $this->container->serviceProvider->get($type);
        $migration->runDown();
    }
}
