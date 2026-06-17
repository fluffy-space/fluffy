<?php

namespace Fluffy\Migrations;

interface IMigrationsContext
{
    function run();

    /**
     * Inspect migrations without applying them.
     * @return array<int, array{name: string, applied: bool}>
     */
    function status(): array;
}
