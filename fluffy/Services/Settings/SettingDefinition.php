<?php

namespace Fluffy\Services\Settings;

/**
 * A code-declared setting — its type, admin-UI metadata, choice list, and
 * default source. Registered in memory via SettingsRegistry::define() at boot
 * (no DB write). The DB only stores an override once someone sets a value, so a
 * defined-but-untouched setting resolves to its default here.
 */
class SettingDefinition
{
    /**
     * @param string $key       Dotted key, e.g. 'paddle.apiKey'.
     * @param string $type      string|boolean|number|json|date|dropdown|multiselect.
     * @param ?string $group    Admin-UI grouping.
     * @param ?string $label    Human title (falls back to key).
     * @param ?string $description Help text.
     * @param ?array $options   Allowed choices for dropdown/multiselect (scalars or ['value'=>, 'label'=>]).
     * @param mixed $initial    Literal default value.
     * @param ?string $seedFrom Dotted config path whose value is the default (takes precedence over $initial).
     */
    public function __construct(
        public string $key,
        public string $type = 'string',
        public ?string $group = null,
        public ?string $label = null,
        public ?string $description = null,
        public ?array $options = null,
        public mixed $initial = null,
        public ?string $seedFrom = null,
    ) {}
}
