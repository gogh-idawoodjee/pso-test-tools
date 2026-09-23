<?php

namespace App\Support;

use App\Models\Environment;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds the pso-environments.psd1 PowerShell data file (and a JSON equivalent)
 * from the current user's environments. Passwords are never exported.
 */
class PsoEnvironmentsExporter
{
    public const string FILENAME = 'pso-environments.psd1';

    private const string GATEWAY_PATH = '/IFSSchedulingRESTfulGateway/api/v1';

    /**
     * @param  Collection<int, Environment>  $environments
     * @return array<string, array{GatewayBaseUrl: string, AccountId: string, UserId: string}>
     */
    public function entries(Collection $environments): array
    {
        $entries = [];

        foreach ($environments as $environment) {
            $key = $this->uniqueKey($this->keyFor((string) $environment->name), $entries);

            $entries[$key] = [
                'GatewayBaseUrl' => rtrim((string) $environment->base_url, '/').self::GATEWAY_PATH,
                'AccountId' => (string) $environment->account_id,
                'UserId' => (string) $environment->username,
            ];
        }

        return $entries;
    }

    /**
     * @param  Collection<int, Environment>  $environments
     */
    public function toJson(Collection $environments): string
    {
        return json_encode((object) $this->entries($environments), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  Collection<int, Environment>  $environments
     */
    public function toPsd1(Collection $environments): string
    {
        $lines = [
            '@{',
            '    # One key per PSO environment. The key name is what you pass to -Environment',
            '    # (and is also what\'s used to build the PSO_PWD_<Environment> env var name',
            '    # for the password - see -StorePassword in the script\'s help).',
            '    #',
            '    # No passwords in this file - those live in PSO_PWD_<Environment> env vars,',
            '    # set via -StorePassword or [Environment]::SetEnvironmentVariable(...). This',
            '    # file only holds connection info, so it\'s safe to commit alongside the script.',
            '',
        ];

        foreach ($this->entries($environments) as $key => $entry) {
            $lines[] = "    {$key} = @{";
            $lines[] = '        GatewayBaseUrl = '.$this->quote($entry['GatewayBaseUrl']);
            $lines[] = '        AccountId      = '.$this->quote($entry['AccountId']);
            $lines[] = '        UserId         = '.$this->quote($entry['UserId']);
            $lines[] = '    }';
            $lines[] = '';
        }

        array_push(
            $lines,
            '    # Add more environments here as needed, e.g.:',
            '    # conocoUat = @{',
            '    #     GatewayBaseUrl = "https://pso-uat.example.com/IFSSchedulingRESTfulGateway/api/v1"',
            '    #     AccountId      = "Default"',
            '    #     UserId         = "INT_USER"',
            '    # }',
            '}',
        );

        return implode("\n", $lines)."\n";
    }

    /**
     * Turns an environment name into a bare PowerShell hashtable key that is also
     * safe inside an env var name (PSO_PWD_<key>): camelCase, alphanumerics only.
     */
    private function keyFor(string $name): string
    {
        $key = Str::camel(preg_replace('/[^A-Za-z0-9]+/', ' ', Str::ascii($name)));

        if ($key === '') {
            return 'environment';
        }

        return ctype_digit($key[0]) ? 'env'.$key : $key;
    }

    /**
     * @param  array<string, mixed>  $existing
     */
    private function uniqueKey(string $key, array $existing): string
    {
        $candidate = $key;
        $suffix = 2;

        // PowerShell hashtable keys are case-insensitive, so compare that way.
        $taken = array_map(strtolower(...), array_keys($existing));

        while (in_array(strtolower($candidate), $taken, true)) {
            $candidate = $key.$suffix++;
        }

        return $candidate;
    }

    /**
     * Double-quoted PowerShell string, escaping the characters that would otherwise
     * be interpreted (backtick, $, and the quote itself).
     */
    private function quote(string $value): string
    {
        return '"'.strtr($value, ['`' => '``', '$' => '`$', '"' => '`"']).'"';
    }
}
