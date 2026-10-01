<?php

namespace Tests\Support;

/**
 * Builds small, fake DsSystemData documents for tests. Never holds real customer data.
 */
class SysFileBuilder
{
    public const string NAMESPACE_URI = 'http://360Scheduling.com/Schema/dsSystemData.xsd';

    /** @var list<array{0: string, 1: array<string, string>}> */
    private array $rows = [];

    private bool $withNamespace = true;

    public static function make(): static
    {
        return new static;
    }

    public function withoutNamespace(): static
    {
        $this->withNamespace = false;

        return $this;
    }

    /**
     * @param  array<string, string>  $fields
     */
    public function row(string $table, array $fields): static
    {
        $this->rows[] = [$table, $fields];

        return $this;
    }

    public function parameter(string $profile, string $parameter, ?string $value, string $applicationType = 'ALL'): static
    {
        return $this->row('Profile_Parameter', array_filter([
            'profile_id' => $profile,
            'parameter_id' => $parameter,
            'parameter_application_type_id' => $applicationType,
            'parameter_value' => $value,
        ], static fn (?string $field): bool => $field !== null));
    }

    public function group(string $id, ?string $parent = null, string $description = ''): static
    {
        return $this->row('Groups', array_filter([
            'id' => $id,
            'group_id' => $parent,
            'description' => $description,
        ], static fn (?string $field): bool => $field !== null));
    }

    public function groupPermission(string $group, string $permission, bool $allow, bool $allowEdit = false): static
    {
        return $this->row('Group_Permission', [
            'group_id' => $group,
            'permission_id' => $permission,
            'allow' => $allow ? 'true' : 'false',
            'allow_edit' => $allowEdit ? 'true' : 'false',
        ]);
    }

    public function exceptionType(string $profile, string $typeId, bool $active, string $attention = '10', string $description = ''): static
    {
        return $this->row('Org_Schedule_Exception_Type', [
            'profile_id' => $profile,
            'schedule_exception_type_id' => $typeId,
            'active' => $active ? 'true' : 'false',
            'attention_value' => $attention,
            'description' => $description,
        ]);
    }

    public function version(string $version, string $type, string $stamp, string $user = 'DBA'): static
    {
        return $this->row('System_Version', [
            'version_id' => $version,
            'version_type' => $type,
            'version_user' => $user,
            'version_stamp' => $stamp,
        ]);
    }

    public function user(string $id, string $name = 'Fake Person'): static
    {
        return $this->row('Users', ['id' => $id, 'name' => $name]);
    }

    public function toXml(): string
    {
        $namespace = $this->withNamespace ? ' xmlns="'.self::NAMESPACE_URI.'"' : '';
        $xml = '<?xml version="1.0" encoding="utf-8"?>'."\n<DsSystemData{$namespace}>\n";

        foreach ($this->rows as [$table, $fields]) {
            $xml .= "  <{$table}>";

            foreach ($fields as $name => $value) {
                $xml .= $value === '' ? "<{$name} />" : "<{$name}>".htmlspecialchars($value, ENT_XML1 | ENT_QUOTES)."</{$name}>";
            }

            $xml .= "</{$table}>\n";
        }

        return $xml.'</DsSystemData>';
    }

    /**
     * Writes the document to a unique temp file and returns its path.
     */
    public function write(string $name = 'sys'): string
    {
        $directory = storage_path('framework/testing');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory.'/syscompare-'.$name.'-'.bin2hex(random_bytes(4)).'.xml';
        file_put_contents($path, $this->toXml());

        return $path;
    }
}
