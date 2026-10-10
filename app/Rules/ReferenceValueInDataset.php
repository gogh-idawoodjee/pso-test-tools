<?php

namespace App\Rules;

use App\Enums\ReferenceEntityType;
use App\Models\DatasetReferenceValue;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** The chosen reference value must belong to the given dataset and be of the given type. */
class ReferenceValueInDataset implements ValidationRule
{
    public function __construct(private readonly ?string $datasetId, private readonly ReferenceEntityType $type) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $matches = DatasetReferenceValue::query()
            ->whereKey($value)
            ->where('dataset_id', $this->datasetId)
            ->ofType($this->type)
            ->exists();

        if (! $matches) {
            $fail("The selected {$this->type->getLabel()} does not belong to the chosen dataset.");
        }
    }
}
