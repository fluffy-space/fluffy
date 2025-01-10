<?php

namespace Fluffy\Domain\App;

use DotDi\DependencyInjection\IServiceProvider;

interface IStartUp
{    
    function configure(BaseApp $app);
    function configureServices(IServiceProvider $serviceProvider): void;
    function configureDb(IServiceProvider $serviceProvider): void;
    function configureMigrations(IServiceProvider $serviceProvider): void;
    function configureInstallDependencies(IServiceProvider $serviceProvider): void;
    /**
     * build before running in CLI mode
     * @return void 
     * @throws ReflectionException 
     * @throws Exception 
     */
    function buildDependencies();
}