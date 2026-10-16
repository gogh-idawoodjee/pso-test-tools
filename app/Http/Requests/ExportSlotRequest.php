<?php

namespace App\Http\Requests;

use App\Enums\ExportKind;
use App\Models\Dataset;
use App\Models\Environment;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportSlotRequest extends FormRequest
{
    public const array SCOPES = [
        'dataset' => Dataset::class,
        'environment' => Environment::class,
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maximumBytes = (int) config('exports.max_file_megabytes', 400) * 1024 * 1024;

        return [
            'scope_type' => ['required', Rule::in(array_keys(self::SCOPES))],
            'scope_id' => ['required', 'uuid'],
            'kind' => ['required', Rule::enum(ExportKind::class)],
            'file_name' => ['required', 'string', 'max:255'],
            'file_size' => ['required', 'integer', 'min:1', 'max:'.$maximumBytes],
        ];
    }

    public function messages(): array
    {
        return [
            'file_size.max' => 'The file is larger than '.(int) config('exports.max_file_megabytes', 400).' MB.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $kind = ExportKind::from((string) $this->input('kind'));

            if (! $kind->allows(self::SCOPES[$this->input('scope_type')])) {
                $validator->errors()->add('kind', ucfirst(self::withArticle($kind->getLabel())).' export cannot be uploaded to '.self::withArticle((string) $this->input('scope_type')).'.');

                return;
            }

            $extension = strtolower(pathinfo((string) $this->input('file_name'), PATHINFO_EXTENSION));
            if (! in_array($extension, $kind->extensions(), true)) {
                $validator->errors()->add('file_name', ucfirst(self::withArticle($kind->getLabel())).' export must be a .'.implode(', .', $kind->extensions()).' file.');
            }
        });
    }

    /** "an IFS event log", "an Audit messages", "a LOAD": the article goes by the first letter. */
    private static function withArticle(string $label): string
    {
        return (preg_match('/^[aeiou]/i', $label) === 1 ? 'an ' : 'a ').$label;
    }

    public function exportKind(): ExportKind
    {
        return ExportKind::from((string) $this->validated('kind'));
    }
}
