<?php

namespace Fluffy\Data\Entities\Settings;

use Fluffy\Data\Entities\BaseEntity;

/**
 * A single runtime setting — a self-describing key/value row. Unlike `configs`
 * (build-time, redeploy to change), settings are edited at runtime through the
 * SettingsService / Paws admin page. Each row carries its own value-type and
 * (for choice types) option list, so the admin UI renders the right editor
 * without any code registry.
 *
 * Values are stored as plain text, serialized per `Type`:
 *  - string      : raw text
 *  - boolean     : "true" / "false"
 *  - number      : numeric text ("29", "8.5")
 *  - json        : JSON
 *  - date        : ISO-8601 ("2026-07-07" or full datetime)
 *  - dropdown    : the chosen option's value
 *  - multiselect : JSON array of chosen values
 */
class SettingEntity extends BaseEntity
{
    /** Dotted key, e.g. 'paddle.apiKey'. Unique. */
    public string $Key;
    /** Current value as text, serialized per Type. */
    public ?string $Value = null;
    /** Value type / editor: string|boolean|number|json|date|dropdown|multiselect. */
    public string $Type = 'string';
    /** JSON array of allowed choices for dropdown/multiselect (scalars or {value,label}); null otherwise. */
    public ?string $Options = null;
    /** Admin-UI grouping, e.g. 'paddle'. */
    public ?string $Group = null;
    /** Human title for the admin UI (falls back to Key). */
    public ?string $Label = null;
    /** Help text shown under the field in the admin UI. */
    public ?string $Description = null;
}
