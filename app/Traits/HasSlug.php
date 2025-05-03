<?php

namespace App\Traits;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;

trait HasSlug
{
    /**
     * Boot the trait.
     *
     * @return void
     */
    public static function bootHasSlug(): void
    {
        static::creating(static function (Model $model) {
            $model->generateSlug();
        });

        static::updating(static function (Model $model) {
            // Only regenerate slug if the source field has changed
            if ($model->isDirty($model->getSlugSourceField())) {
                $model->generateSlug();
            }
        });
    }

    /**
     * Generate a unique slug.
     *
     * @return void
     */
    public function generateSlug(): void
    {
        if (empty($this->{$this->getSlugField()})) {
            $source = $this->{$this->getSlugSourceField()};
            $base = Str::slug($source);
            $slug = $base;
            $i = 1;

            // Check for existing slugs
            while (static::where($this->getSlugField(), $slug)
                ->where($this->getKeyName(), '!=', $this->getKey())
                ->exists()) {
                $slug = "{$base}-{$i}";
                $i++;
            }

            $this->{$this->getSlugField()} = $slug;
        }
    }

    /**
     * Get the field that should be used for the slug.
     *
     * @return string
     */
    public function getSlugField(): string
    {
        return 'slug';
    }

    /**
     * Get the source field that should be used to generate the slug.
     *
     * @return string
     */
    public function getSlugSourceField(): string
    {
        return 'name';
    }
}
