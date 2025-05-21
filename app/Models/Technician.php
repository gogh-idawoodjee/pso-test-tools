<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class Technician extends Model
{


    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'resource_id',
        'personal',
        'additional_attributes',
        'resource_type',
        'note',
        'max_travel',
        'max_travel_outside_shift_to_first_activity',
        'max_travel_outside_shift_to_home',
        'location',
        'regions',
        'skills',
        'shifts',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'personal' => AsCollection::class,
        'additional_attributes' => AsCollection::class,
        'resource_type' => AsCollection::class,
        'max_travel' => AsCollection::class,
        'max_travel_outside_shift_to_first_activity' => AsCollection::class,
        'max_travel_outside_shift_to_home' => AsCollection::class,
        'location' => AsCollection::class,
        'regions' => AsCollection::class,
        'skills' => AsCollection::class,
        'shifts' => AsCollection::class,
    ];

    /**
     * Get the technician's full name.
     *
     * @return string
     */
    public function getFullNameAttribute()
    {
        return $this->personal['full_name'] ?? '';
    }

    /**
     * Get the count of skills.
     *
     * @return int
     */
    public function getSkillsCountAttribute()
    {
        if (!isset($this->skills)) {
            return 0;
        }

        // Filter out the 'total' element
        $filteredSkills = collect($this->skills)->filter(function ($skill) {
            return !isset($skill['total']);
        });

        return $filteredSkills->count();
    }

    /**
     * Get the count of regions.
     *
     * @return int
     */
    public function getRegionsCountAttribute()
    {
        if (!isset($this->regions)) {
            return 0;
        }

        // Filter out the 'total' element
        $filteredRegions = collect($this->regions)->filter(function ($region) {
            return !isset($region['total']);
        });

        return $filteredRegions->count();
    }

    /**
     * Get the count of shifts.
     *
     * @return int
     */
    public function getShiftsCountAttribute()
    {
        return $this->shifts['total_shifts'] ?? 0;
    }

    /**
     * Get the location coordinates.
     *
     * @return array
     */
    public function getLocationCoordinatesAttribute()
    {
        return [
            'latitude' => $this->location['pso']['start']['latitude'] ?? null,
            'longitude' => $this->location['pso']['start']['longitude'] ?? null,
        ];
    }

    /**
     * Get the average utilization of shifts.
     *
     * @return float
     */
    public function getAverageUtilizationAttribute()
    {
        if (!isset($this->shifts['shifts']) || empty($this->shifts['shifts'])) {
            return 0;
        }

        $total = 0;
        $count = 0;

        foreach ($this->shifts['shifts'] as $shift) {
            if (isset($shift['utilisation']['percent'])) {
                $total += (float)$shift['utilisation']['percent'];
                $count++;
            }
        }

        return $count > 0 ? $total / $count : 0;
    }

    /**
     * Static method to import from JSON.
     *
     * @param array $data
     * @return self
     */
    public static function importFromJson($data)
    {
        if (isset($data['data']['resource'])) {
            $resourceData = $data['data']['resource'];

            return self::updateOrCreate(
                ['resource_id' => $resourceData['resource_id']],
                [
                    'personal' => $resourceData['personal'],
                    'additional_attributes' => $resourceData['additional_attributes'],
                    'resource_type' => $resourceData['resource_type'],
                    'note' => $resourceData['note'],
                    'max_travel' => $resourceData['max_travel'],
                    'max_travel_outside_shift_to_first_activity' => $resourceData['max_travel_outside_shift_to_first_activity'],
                    'max_travel_outside_shift_to_home' => $resourceData['max_travel_outside_shift_to_home'],
                    'location' => $resourceData['location'],
                    'regions' => $resourceData['regions'],
                    'skills' => $resourceData['skills'],
                    'shifts' => $resourceData['shifts'],
                ]
            );
        }

        throw new RuntimeException("Invalid JSON structure");
    }
}
