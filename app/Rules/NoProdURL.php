<?php

namespace App\Rules;

use Closure;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

class NoProdURL implements ValidationRule
{
//
//    /**
//     * Determine if the validation rule passes.
//     *
//     * @param string $attribute
//     * @param mixed $value
//     * @return bool
//     */
//    public function passes(string $attribute, mixed $value): bool
//    {
//        return !Str::contains(Str::lower($value), ['prd', 'prod', 'pd']);
//    }
//
//    /**
//     * Get the validation error message.
//     *
//     * @return string
//     */
//    public function message(): string
//    {
//        return 'Not allowed to use this rota thingy in prod environments.';
//    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // TODO: Implement validate() method.
        if (Str::contains(Str::lower($value), ['prd', 'prod', 'pd'])) {
            $fail('No Prod Environments Allowed');

        }
    }
}
