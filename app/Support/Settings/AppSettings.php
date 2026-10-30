<?php

namespace App\Support\Settings;

use App\Models\AppSetting;
use App\Tools\LoadAudit\LoadAuditCommand;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AppSettings
{
    public const NOISE_EVENT_IDS = 'load_audit.noise_event_ids';

    /** The engine's own default noise list (system_day.DEFAULT_NOISE), shown on the Settings page. */
    public const SHIPPED_NOISE_EVENT_IDS = '3041,1767,2959,1964,1965,24578';

    public const COMPLETE_GRACE_HOURS = 'load_audit.complete_grace_hours';

    public const FLAG_ACTIVITIES = 'load_audit.flag_activities';

    public const FLAG_DELETIONS = 'load_audit.flag_deletions';

    public const MESSAGE_TEXTS = 'message_texts';

    /** The Gateway upload's webhook Broadcast defaults; null until saved, so config/psott.php's shipped values apply. */
    public const GATEWAY_BROADCAST = 'gateway_upload.broadcast';

    /** Customer map tiles (url, subdomains, max_zoom, attribution); null until saved, so config psott.map_tiles applies. */
    public const MAP_TILES = 'map_tiles';

    /** Seconds per call (default, session, appointment, routes, reference_values); null until saved, so config psott applies. */
    public const TIMEOUTS = 'timeouts';

    /** Each timeout and the config key that ships its value. */
    public const TIMEOUT_CONFIG_KEYS = [
        'default' => 'psott.defaults.timeout',
        'session' => 'psott.session.timeout',
        'appointment' => 'psott.appointment.timeout',
        'routes' => 'psott.routes.timeout',
        'reference_values' => 'psott.reference_values.timeout',
    ];

    public const MAP_TILE_FIELDS = ['url', 'subdomains', 'max_zoom', 'attribution'];

    public const PARAM_DEFINITIONS = 'param_definitions';

    public const RELEASE_NOTES = 'release_notes';

    public const PARAM_REFERENCE = 'param_reference';

    public const CACHE_KEY = 'app_settings';

    /** Bounded so a read that refills the cache just after a write's forget cannot keep an old snapshot for good. */
    public const CACHE_SECONDS = 300;

    private const DEFAULTS = [
        self::NOISE_EVENT_IDS => self::SHIPPED_NOISE_EVENT_IDS,
        self::COMPLETE_GRACE_HOURS => 24,
        self::FLAG_ACTIVITIES => 5,
        self::FLAG_DELETIONS => 200,
        self::MESSAGE_TEXTS => null,
        self::GATEWAY_BROADCAST => null,
        self::MAP_TILES => null,
        self::TIMEOUTS => null,
        self::PARAM_DEFINITIONS => null,
        self::RELEASE_NOTES => null,
        self::PARAM_REFERENCE => null,
    ];

    public function get(string $key): mixed
    {
        if (! array_key_exists($key, self::DEFAULTS)) {
            throw new InvalidArgumentException("Unknown setting key [{$key}].");
        }

        $savedValues = $this->savedValues();

        return array_key_exists($key, $savedValues) ? $savedValues[$key] : self::DEFAULTS[$key];
    }

    public function put(string $key, mixed $value, ?int $userId = null): void
    {
        if (! array_key_exists($key, self::DEFAULTS)) {
            throw new InvalidArgumentException("Unknown setting key [{$key}].");
        }

        AppSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $userId],
        );

        Cache::forget(self::CACHE_KEY);
    }

    public function forget(string $key): void
    {
        AppSetting::query()->whereKey($key)->delete();

        Cache::forget(self::CACHE_KEY);
    }

    public function noiseEventIds(): string
    {
        return (string) $this->get(self::NOISE_EVENT_IDS);
    }

    public function completeGraceHours(): int
    {
        return (int) $this->get(self::COMPLETE_GRACE_HOURS);
    }

    public function flagActivities(): int
    {
        return (int) $this->get(self::FLAG_ACTIVITIES);
    }

    public function flagDeletions(): int
    {
        return (int) $this->get(self::FLAG_DELETIONS);
    }

    public function saveLoadAuditDefaults(string $noiseEventIds, int $completeGraceHours, int $flagActivities, int $flagDeletions, ?int $userId): void
    {
        $this->put(self::NOISE_EVENT_IDS, LoadAuditCommand::normalisedIds($noiseEventIds), $userId);
        $this->put(self::COMPLETE_GRACE_HOURS, $completeGraceHours, $userId);
        $this->put(self::FLAG_ACTIVITIES, $flagActivities, $userId);
        $this->put(self::FLAG_DELETIONS, $flagDeletions, $userId);
    }

    /** @return array<string, mixed>|null id, plan_type, minimum_plan_quality, maximum_wait, maximum_frequency, allocation_type, required_tables */
    public function gatewayBroadcastDefaults(): ?array
    {
        $value = $this->get(self::GATEWAY_BROADCAST);

        return is_array($value) ? $value : null;
    }

    /** @param array<string, mixed> $defaults as BroadcastSettings::toArray() */
    public function saveGatewayBroadcastDefaults(array $defaults, ?int $userId): void
    {
        $this->put(self::GATEWAY_BROADCAST, $defaults, $userId);
    }

    /**
     * The map tile settings: the Settings page's values over the shipped ones.
     *
     * @return array{url: string, subdomains: string, max_zoom: int, attribution: string}
     */
    public function mapTiles(): array
    {
        $saved = $this->get(self::MAP_TILES);
        $saved = is_array($saved) ? $saved : [];
        $tiles = [];

        foreach (self::MAP_TILE_FIELDS as $field) {
            $tiles[$field] = array_key_exists($field, $saved) ? $saved[$field] : config("psott.map_tiles.{$field}");
        }

        return [
            'url' => (string) $tiles['url'],
            'subdomains' => (string) $tiles['subdomains'],
            'max_zoom' => (int) $tiles['max_zoom'],
            'attribution' => (string) $tiles['attribution'],
        ];
    }

    /** @return array{url: string, subdomains: string, max_zoom: int, attribution: string} */
    public function shippedMapTiles(): array
    {
        return [
            'url' => (string) config('psott.map_tiles.url'),
            'subdomains' => (string) config('psott.map_tiles.subdomains'),
            'max_zoom' => (int) config('psott.map_tiles.max_zoom'),
            'attribution' => (string) config('psott.map_tiles.attribution'),
        ];
    }

    /** Seconds allowed for one call of this kind (a TIMEOUT_CONFIG_KEYS name): the Settings page's value, else the shipped one. */
    public function timeout(string $name): int
    {
        if (! array_key_exists($name, self::TIMEOUT_CONFIG_KEYS)) {
            throw new InvalidArgumentException("Unknown timeout [{$name}].");
        }

        $saved = $this->get(self::TIMEOUTS);

        return is_array($saved) && isset($saved[$name])
            ? (int) $saved[$name]
            : $this->shippedTimeout($name);
    }

    public function shippedTimeout(string $name): int
    {
        return (int) config(self::TIMEOUT_CONFIG_KEYS[$name]);
    }

    /** @return array<string, int> every timeout by name */
    public function timeouts(): array
    {
        $timeouts = [];

        foreach (array_keys(self::TIMEOUT_CONFIG_KEYS) as $name) {
            $timeouts[$name] = $this->timeout($name);
        }

        return $timeouts;
    }

    /**
     * @param  array{url: string, subdomains: string, max_zoom: int, attribution: string}  $mapTiles
     * @param  array<string, int>  $timeouts  by TIMEOUT_CONFIG_KEYS name
     */
    public function saveConnectionDefaults(array $mapTiles, array $timeouts, ?int $userId): void
    {
        $this->put(self::MAP_TILES, $mapTiles, $userId);
        $this->put(self::TIMEOUTS, $timeouts, $userId);
    }

    /** @return array<mixed>|null */
    public function messageTexts(): ?array
    {
        $value = $this->get(self::MESSAGE_TEXTS);

        return is_array($value) ? $value : null;
    }

    /** @return array<mixed>|null */
    /** @return array<string, mixed>|null file_name, build, date, fixes, kept, uploaded_by, uploaded_at */
    public function releaseNotes(): ?array
    {
        $value = $this->get(self::RELEASE_NOTES);

        return is_array($value) ? $value : null;
    }

    /** @return array<string, mixed>|null file_name, rows, pso_version, uploaded_by, uploaded_at */
    public function paramReference(): ?array
    {
        $value = $this->get(self::PARAM_REFERENCE);

        return is_array($value) ? $value : null;
    }

    public function paramDefinitions(): ?array
    {
        $value = $this->get(self::PARAM_DEFINITIONS);

        return is_array($value) ? $value : null;
    }

    public function updatedAt(string $key): ?CarbonImmutable
    {
        return AppSetting::query()->find($key)?->updated_at;
    }

    public function updatedByName(string $key): ?string
    {
        $name = DB::table('app_settings')
            ->join('users', 'users.id', '=', 'app_settings.updated_by')
            ->where('app_settings.key', $key)
            ->value('users.name');

        return $name === null ? null : (string) $name;
    }

    /** @return array<string, mixed> */
    private function savedValues(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, static fn (): array => AppSetting::query()
            ->get()
            ->mapWithKeys(static fn (AppSetting $setting): array => [$setting->key => $setting->value])
            ->all());
    }
}
