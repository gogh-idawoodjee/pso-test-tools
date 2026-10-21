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

    public const COMPLETE_GRACE_HOURS = 'load_audit.complete_grace_hours';

    public const FLAG_ACTIVITIES = 'load_audit.flag_activities';

    public const FLAG_DELETIONS = 'load_audit.flag_deletions';

    public const MESSAGE_TEXTS = 'message_texts';

    public const PARAM_DEFINITIONS = 'param_definitions';

    public const CACHE_KEY = 'app_settings';

    private const DEFAULTS = [
        self::NOISE_EVENT_IDS => '3041,1767,2959,1964,1965,24578',
        self::COMPLETE_GRACE_HOURS => 24,
        self::FLAG_ACTIVITIES => 5,
        self::FLAG_DELETIONS => 30,
        self::MESSAGE_TEXTS => null,
        self::PARAM_DEFINITIONS => null,
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

    /** @return array<mixed>|null */
    public function messageTexts(): ?array
    {
        $value = $this->get(self::MESSAGE_TEXTS);

        return is_array($value) ? $value : null;
    }

    /** @return array<mixed>|null */
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
        return Cache::rememberForever(self::CACHE_KEY, static fn (): array => AppSetting::query()
            ->get()
            ->mapWithKeys(static fn (AppSetting $setting): array => [$setting->key => $setting->value])
            ->all());
    }
}
