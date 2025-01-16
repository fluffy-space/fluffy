<?php

namespace Fluffy\Migrations;

use Fluffy\Migrations\Auth\SessionMigration;
use Fluffy\Migrations\Auth\UsersMigration;
use DotDi\DependencyInjection\Container;
use Fluffy\Migrations\Auth\UserTokenMigration;
use Fluffy\Migrations\Auth\UserVerificationCodeMigration;
use Fluffy\Migrations\InstallMigration;

class BaseMigrationsContext implements IMigrationsContext
{
    public function __construct(protected Container $container) {}

    public function run()
    {
        $this->runMigration(InstallMigration::class);
        $this->runMigration(UsersMigration::class);
        $this->runMigration(SessionMigration::class);
        $this->runMigration(UserTokenMigration::class);
        $this->runMigration(UserVerificationCodeMigration::class);
    }

    public function runMigration(string $type)
    {
        /** @var BaseMigration $migration */
        $migration = $this->container->serviceProvider->get($type);
        $migration->runUp();
    }

    public function rollback(string $type)
    {
        /** @var BaseMigration $migration */
        $migration = $this->container->serviceProvider->get($type);
        $migration->runDown();
    }
}
