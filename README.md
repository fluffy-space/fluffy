# fluffy

An async PHP application framework built on [Swoole](https://swoole.com) and
[Viewi](https://viewi.net) - one language for the server and the browser. Viewi's build step uses
Node, but a built app runs on PHP alone: no Node on the live server.

## What it gives you

- **Async HTTP server on Swoole** — long-lived worker processes with connection pooling, not a
  per-request bootstrap.
- **Viewi frontend** — components written in PHP, transpiled to JavaScript, server-side rendered
  and hydrated in the browser.
- **ORM** — entity + map classes, a fluent query builder, paginated search and merge/upsert, with
  migrations and code generation (`php fluffy model create|build`).
- **DI container**, routing, middleware pipeline, and a job/cron runner on Swoole's task pool.
- **PostgreSQL, Redis and ClickHouse** integrations, with pooled connections for each.

## Status

Used in production: it runs [urlicer.com](https://urlicer.com) — link shortening, redirects,
click analytics on ClickHouse, and per-SNI TLS for customer domains.

It is, however, a one-developer framework built alongside the application that uses it. Public APIs
still change between versions, the documentation below is a command reference rather than a guide,
and there is no deprecation policy yet. Read it as "production-proven on one product," not
"production-ready for yours."

## Commands

### Set up Nginx on WSL

`php fluffy nginx nutrition.wsl.com`

### Run server

`php fluffy server`

### Reload server

`php fluffy reload`

### Watch mode - runs server and rebuilds application on file changes

`php fluffy watch`

### Build - builds the app

`php fluffy build [environment]`

For example:

`php fluffy build dev`

`php fluffy build prod`

`php fluffy build local`

### Install (create all tables)

`php fluffy install`

### Run migrations

`php fluffy migrate`

Rollback migration

`php fluffy migrate rollback Application\\Migrations\\Menu\\MenuItemMigration`

### Create Entity model

`php fluffy model create EntityName [Namespace]`

Example:

`php fluffy model create UserTokenEntity Auth`

### Generate Entity model repository, migration, service; register migration, repository, service

`php fluffy model build UserTokenEntity Auth`

### Generate Controller

`Catalog/ProductController`

`php fluffy controller create Product Catalog`

### Generate Viewi admin pages

`php fluffy viewi create [Name] [Folder] [ModelsFolder]`

`php fluffy viewi create Order Order Sales`

### Cron Tab

`Application\crontab.php`

```php
CronTab::schedule([TestTask::class, 'execute'], '*/5 * * * * *');
```

### Hubs (Web sockets)

`Application\hubs.php`

```php
Hubs::mapHub('collect', [CollectHub::class, 'collect']);
```

```php
<?php

namespace Application\Hubs;

use Application\Models\CollectModel;

class CollectHub
{
    public function collect(CollectModel $message, $data, string $name)
    {
        print_r(['CollectHub::collect', $message->date, $data, $name]);
    }
}
```

```js
websocket.send(JSON.stringify({ 
  route: 'collect',
  data: { 
    name: 'Viewi',
    date: 123
  }
}));
```

## Controllers

Optionally, BaseController provides default response methods

`use Fluffy\Controllers\BaseController`

```php
<?php

namespace Application\Controllers;

use Fluffy\Controllers\BaseController;

class TestController extends BaseController
{
}
```
