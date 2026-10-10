<?php

namespace App\Casts;

use App\Support\DatasetSettings;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads datasets.settings as a DatasetSettings (defaults when the column is
 * null) and validates whatever is assigned before it is written.
 */
class DatasetSettingsCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): DatasetSettings
    {
        return DatasetSettings::fromArray(json_decode($value ?? '{}', true) ?: []);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $settings = $value instanceof DatasetSettings ? $value : DatasetSettings::fromArray((array) $value);

        return json_encode($settings->toArray());
    }
}
