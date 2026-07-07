<?php

namespace Fluffy\Services\Settings;

use Fluffy\Data\Entities\Settings\SettingEntity;
use Fluffy\Data\Entities\Settings\SettingEntityMap;
use Fluffy\Data\Repositories\SettingRepository;
use Fluffy\Domain\Configuration\Config;
use Fluffy\Swoole\Cache\CacheManager;

/**
 * Runtime settings — read/write the DB-backed key/value store, cached in memory.
 *
 * Resolution for a read: DB override (a row exists) → code-declared default
 * (SettingsRegistry, resolving `seedFrom` against `config`) → caller default.
 *
 * The whole table is read once per worker through CacheManager under one key
 * (SettingEntityMap::CACHE_KEY); every write bumps the shared invalidation
 * marker so all workers reload on their next read (refresh-on-change). Scoped
 * (per-request) — the per-worker cache lives in the singleton CacheManager, not
 * here, so a fresh connector is used per request.
 */
class SettingsService
{
    /** Allowed value types (also the admin editor kinds). */
    public const TYPES = ['string', 'boolean', 'number', 'json', 'date', 'dropdown', 'multiselect'];

    public function __construct(
        private SettingRepository $repo,
        private CacheManager $cache,
        private Config $config,
    ) {}

    // --- typed reads --------------------------------------------------------

    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->map()[$key] ?? null;
        if ($row !== null && $row['Value'] !== null) {
            return $this->parse($row['Value'], $row['Type']);
        }
        $definition = SettingsRegistry::definition($key);
        if ($definition !== null) {
            $declaredDefault = $this->defaultOf($definition);
            if ($declaredDefault !== null) {
                return $declaredDefault;
            }
        }
        return $default;
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key);
        return $value === null ? $default : (string)$value;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        return $value === null ? $default : (bool)$value;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        return $value === null ? $default : (int)$value;
    }

    public function getFloat(string $key, float $default = 0.0): float
    {
        $value = $this->get($key);
        return $value === null ? $default : (float)$value;
    }

    public function getJson(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key);
        return $value === null ? $default : $value;
    }

    public function getArray(string $key, array $default = []): array
    {
        $value = $this->get($key);
        return is_array($value) ? $value : $default;
    }

    /** ISO-8601 date → epoch seconds (or $default on absent/unparseable). */
    public function getDate(string $key, ?int $default = null): ?int
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        if (is_int($value)) {
            return $value;
        }
        $timestamp = strtotime((string)$value);
        return $timestamp === false ? $default : $timestamp;
    }

    public function has(string $key): bool
    {
        return isset($this->map()[$key]) || SettingsRegistry::isDefined($key);
    }

    /** True when the key is declared in code (vs. an admin-created dynamic row). */
    public function isDefined(string $key): bool
    {
        return SettingsRegistry::isDefined($key);
    }

    // --- writes -------------------------------------------------------------

    /**
     * Set (materialize) a value. A defined key inherits Type/Options/metadata
     * from its declaration on first write; an undefined key infers its Type
     * from the PHP value (string|boolean|number|json).
     */
    public function set(string $key, mixed $value): void
    {
        /** @var ?SettingEntity $entity */
        $entity = $this->repo->find(SettingEntityMap::PROPERTY_Key, $key);
        if ($entity === null) {
            $definition = SettingsRegistry::definition($key);
            $type = $definition?->type ?? $this->inferType($value);
            $entity = new SettingEntity();
            $entity->Key = $key;
            $entity->Type = $type;
            $entity->Options = $definition?->options !== null ? json_encode($definition->options) : null;
            $entity->Group = $definition?->group;
            $entity->Label = $definition?->label;
            $entity->Description = $definition?->description;
            $entity->Value = $this->serialize($value, $type);
            $this->repo->create($entity);
        } else {
            $entity->Value = $this->serialize($value, $entity->Type);
            $this->repo->update($entity);
        }
        $this->cache->delete(SettingEntityMap::CACHE_KEY);
    }

    /** Persist a full row (admin create/update of a dynamic setting) + invalidate. */
    public function persist(SettingEntity $entity): void
    {
        if (isset($entity->Id) && $entity->Id > 0) {
            $this->repo->update($entity);
        } else {
            $this->repo->create($entity);
        }
        $this->cache->delete(SettingEntityMap::CACHE_KEY);
    }

    public function delete(string $key): void
    {
        /** @var ?SettingEntity $entity */
        $entity = $this->repo->find(SettingEntityMap::PROPERTY_Key, $key);
        if ($entity !== null) {
            $this->repo->delete($entity);
            $this->cache->delete(SettingEntityMap::CACHE_KEY);
        }
    }

    /** Create a brand-new (admin-defined dynamic) setting row. */
    public function create(
        string $key,
        string $type,
        mixed $value,
        ?array $options = null,
        ?string $group = null,
        ?string $label = null,
        ?string $description = null,
    ): void {
        $entity = new SettingEntity();
        $entity->Key = $key;
        $entity->Type = $type;
        $entity->Options = $options !== null ? json_encode($options) : null;
        $entity->Group = $group;
        $entity->Label = $label;
        $entity->Description = $description;
        $entity->Value = $this->serialize($value, $type);
        $this->repo->create($entity);
        $this->cache->delete(SettingEntityMap::CACHE_KEY);
    }

    // --- metadata lookups + validation (for the admin API) ------------------

    /** Effective type of a key (registry declaration wins, else DB row). */
    public function typeOf(string $key): ?string
    {
        $definition = SettingsRegistry::definition($key);
        if ($definition !== null) {
            return $definition->type;
        }
        $row = $this->map()[$key] ?? null;
        return $row['Type'] ?? null;
    }

    /** Effective option list of a key (for dropdown/multiselect). */
    public function optionsOf(string $key): ?array
    {
        $definition = SettingsRegistry::definition($key);
        if ($definition !== null) {
            return $definition->options;
        }
        $row = $this->map()[$key] ?? null;
        return ($row['Options'] ?? null) !== null ? json_decode($row['Options'], true) : null;
    }

    /**
     * Validate a value in its stored text form (as edited on the admin page).
     * @return ?string error message, or null when valid.
     */
    public function validateValue(?string $value, string $type, ?array $options = null): ?string
    {
        $value = $value ?? '';
        switch ($type) {
            case 'boolean':
                if (!in_array($value, ['true', 'false', ''], true)) {
                    return 'Value must be true or false.';
                }
                break;
            case 'number':
                if ($value !== '' && !is_numeric($value)) {
                    return 'Value must be a number.';
                }
                break;
            case 'json':
                if ($value !== '' && json_decode($value) === null && trim($value) !== 'null') {
                    return 'Value must be valid JSON.';
                }
                break;
            case 'date':
                if ($value !== '' && strtotime($value) === false) {
                    return 'Value must be a valid date.';
                }
                break;
            case 'dropdown':
                if ($value !== '' && !$this->inOptions($value, $options)) {
                    return 'Value must be one of the allowed options.';
                }
                break;
            case 'multiselect':
                if ($value !== '') {
                    $decoded = json_decode($value, true);
                    if (!is_array($decoded)) {
                        return 'Value must be a JSON array.';
                    }
                    foreach ($decoded as $item) {
                        if (!$this->inOptions((string)$item, $options)) {
                            return 'All values must be allowed options.';
                        }
                    }
                }
                break;
        }
        return null;
    }

    /**
     * Materialize any code-declared setting that has no DB row yet — so every
     * setting is a real, id-addressable row for the standard admin list/CRUD.
     * Idempotent + race-safe (a concurrent insert just loses the unique race and
     * is ignored). Called by the admin List endpoint; a no-op once seeded.
     */
    public function ensureSeeded(): void
    {
        $map = $this->map();
        $created = false;
        foreach (SettingsRegistry::all() as $key => $definition) {
            if (isset($map[$key])) {
                continue;
            }
            $entity = new SettingEntity();
            $entity->Key = $key;
            $entity->Type = $definition->type;
            $entity->Options = $definition->options !== null ? json_encode($definition->options) : null;
            $entity->Group = $definition->group;
            $entity->Label = $definition->label;
            $entity->Description = $definition->description;
            $entity->Value = $this->serialize($this->defaultOf($definition), $definition->type);
            try {
                $this->repo->create($entity);
                $created = true;
            } catch (\Throwable) {
                // Raced by another worker (unique Key) — the row exists, ignore.
            }
        }
        if ($created) {
            $this->cache->delete(SettingEntityMap::CACHE_KEY);
        }
    }

    // --- admin listing (code-declared ∪ DB rows) ----------------------------

    /**
     * All settings for the admin UI: code-declared ones (with their current
     * effective value + `codeDefined`/`overridden` flags) merged with
     * admin-created dynamic rows. Optionally filtered by group.
     * @return array<int, array>
     */
    public function all(?string $group = null): array
    {
        $map = $this->map();
        $result = [];
        $seen = [];

        foreach (SettingsRegistry::all() as $key => $definition) {
            if ($group !== null && $definition->group !== $group) {
                continue;
            }
            $row = $map[$key] ?? null;
            $result[] = [
                'Key' => $key,
                'Type' => $definition->type,
                'Options' => $definition->options,
                'Group' => $definition->group,
                'Label' => $definition->label,
                'Description' => $definition->description,
                'Value' => ($row !== null && $row['Value'] !== null)
                    ? $this->parse($row['Value'], $definition->type)
                    : $this->defaultOf($definition),
                'codeDefined' => true,
                'overridden' => $row !== null,
            ];
            $seen[$key] = true;
        }

        foreach ($map as $key => $row) {
            if (isset($seen[$key])) {
                continue;
            }
            if ($group !== null && ($row['Group'] ?? null) !== $group) {
                continue;
            }
            $result[] = [
                'Key' => $key,
                'Type' => $row['Type'],
                'Options' => $row['Options'] !== null ? json_decode($row['Options'], true) : null,
                'Group' => $row['Group'],
                'Label' => $row['Label'],
                'Description' => $row['Description'],
                'Value' => $row['Value'] !== null ? $this->parse($row['Value'], $row['Type']) : null,
                'codeDefined' => false,
                'overridden' => true,
            ];
        }

        return $result;
    }

    // --- internals ----------------------------------------------------------

    /** The whole table as [key => row-array], read once per worker via CacheManager. */
    private function map(): array
    {
        $cached = $this->cache->get(SettingEntityMap::CACHE_KEY);
        if ($cached !== null) {
            return $cached;
        }
        return $this->cache->set(SettingEntityMap::CACHE_KEY, function () {
            $rows = $this->repo->search([], [SettingEntityMap::PROPERTY_Key => 1], 1, null, false)['list'];
            $map = [];
            /** @var SettingEntity $row */
            foreach ($rows as $row) {
                $map[$row->Key] = [
                    'Value' => $row->Value,
                    'Type' => $row->Type,
                    'Options' => $row->Options,
                    'Group' => $row->Group,
                    'Label' => $row->Label,
                    'Description' => $row->Description,
                ];
            }
            return $map;
        });
    }

    private function parse(string $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => $value === 'true' || $value === '1',
            'number' => (str_contains($value, '.') || stripos($value, 'e') !== false) ? (float)$value : (int)$value,
            'json', 'multiselect' => json_decode($value, true),
            default => $value, // string, date, dropdown
        };
    }

    private function serialize(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }
        return match ($type) {
            'boolean' => $value ? 'true' : 'false',
            'number' => (string)$value,
            'json', 'multiselect' => is_string($value) ? $value : json_encode($value),
            default => (string)$value,
        };
    }

    private function inferType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            is_array($value) => 'json',
            default => 'string',
        };
    }

    /** Declared default: `seedFrom` config value if present, else the literal `initial`. */
    private function defaultOf(SettingDefinition $definition): mixed
    {
        if ($definition->seedFrom !== null) {
            $fromConfig = $this->configPath($definition->seedFrom);
            if ($fromConfig !== null) {
                return $fromConfig;
            }
        }
        return $definition->initial;
    }

    private function configPath(string $dotted): mixed
    {
        $node = $this->config->values;
        foreach (explode('.', $dotted) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }
        return $node;
    }

    /** The plain value(s) allowed by an option list (unwraps {value,label} entries). */
    private function optionValues(?array $options): array
    {
        if (!$options) {
            return [];
        }
        return array_map(fn($o) => is_array($o) ? ($o['value'] ?? null) : $o, $options);
    }

    private function inOptions(mixed $value, ?array $options): bool
    {
        $allowed = $this->optionValues($options);
        return in_array($value, $allowed, true)
            || in_array((string)$value, array_map('strval', $allowed), true);
    }
}
