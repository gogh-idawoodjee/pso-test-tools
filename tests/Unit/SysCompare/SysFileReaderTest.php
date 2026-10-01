<?php

use App\Support\SysCompare\Exceptions\InvalidSysFile;
use App\Support\SysCompare\SysFileReader;
use Tests\Support\SysFileBuilder;

afterEach(function (): void {
    foreach (glob(storage_path('framework/testing/syscompare-*')) ?: [] as $file) {
        @unlink($file);
    }
});

it('reads a sys file with and without a namespace', function (bool $withNamespace): void {
    $builder = SysFileBuilder::make()->parameter('DEFAULT', 'AllowSplitTravel', 'True');

    if (! $withNamespace) {
        $builder->withoutNamespace();
    }

    $sysFile = app(SysFileReader::class)->read($builder->write());

    expect($sysFile->rows('Profile_Parameter'))->toHaveCount(1)
        ->and($sysFile->rows('Profile_Parameter')[0]['parameter_value'])->toBe('True');
})->with([[true], [false]]);

it('keeps values exactly as stored and tells an empty element from a missing one', function (): void {
    $path = SysFileBuilder::make()
        ->row('Profile_Parameter', ['profile_id' => 'DEFAULT', 'parameter_id' => 'Padded', 'parameter_value' => ' spaced '])
        ->row('Profile_Parameter', ['profile_id' => 'DEFAULT', 'parameter_id' => 'Empty', 'parameter_value' => ''])
        ->row('Profile_Parameter', ['profile_id' => 'DEFAULT', 'parameter_id' => 'Missing'])
        ->write();

    $rows = app(SysFileReader::class)->read($path)->rows('Profile_Parameter');

    expect($rows[0]['parameter_value'])->toBe(' spaced ')
        ->and($rows[1])->toHaveKey('parameter_value', '')
        ->and($rows[2])->not->toHaveKey('parameter_value');
});

it('decodes XML entities in values', function (): void {
    $path = SysFileBuilder::make()->parameter('DEFAULT', 'Url', 'https://x.test/?a=1&b=<2>|{x}')->write();

    $rows = app(SysFileReader::class)->read($path)->rows('Profile_Parameter');

    expect($rows[0]['parameter_value'])->toBe('https://x.test/?a=1&b=<2>|{x}');
});

it('counts every table but only retains the compared ones', function (): void {
    $path = SysFileBuilder::make()
        ->user('u1', 'Alice Example')
        ->user('u2', 'Bob Example')
        ->row('User_Parameter', ['user_id' => 'u1', 'parameter_value' => 'secret'])
        ->parameter('DEFAULT', 'A', '1')
        ->write();

    $sysFile = app(SysFileReader::class)->read($path);

    expect($sysFile->tableCounts)->toBe(['Users' => 2, 'User_Parameter' => 1, 'Profile_Parameter' => 1])
        ->and($sysFile->tables)->toHaveKey('Profile_Parameter')
        ->and($sysFile->tables)->not->toHaveKey('Users')
        ->and($sysFile->tables)->not->toHaveKey('User_Parameter')
        ->and(serialize($sysFile))->not->toContain('Alice Example');
});

it('handles a table that is missing from the file', function (): void {
    $sysFile = app(SysFileReader::class)->read(SysFileBuilder::make()->parameter('DEFAULT', 'A', '1')->write());

    expect($sysFile->rows('Org_Schedule_Exc_Type_Data'))->toBe([])
        ->and($sysFile->hasTable('Org_Schedule_Exc_Type_Data'))->toBeFalse();
});

it('rejects a file whose root element is not DsSystemData, naming the file and nothing else', function (): void {
    $path = storage_path('framework/testing/syscompare-wrong.xml');
    file_put_contents($path, '<dsScheduleData><Secret>do-not-echo-this</Secret></dsScheduleData>');

    try {
        app(SysFileReader::class)->read($path, 'wrong.xml');
        $this->fail('Expected an InvalidSysFile exception.');
    } catch (InvalidSysFile $exception) {
        expect($exception->getMessage())->toContain('wrong.xml')
            ->and($exception->getMessage())->toContain('DsSystemData')
            ->and($exception->getMessage())->not->toContain('do-not-echo-this');
    }
});

it('rejects a file that is not well-formed XML', function (): void {
    $path = storage_path('framework/testing/syscompare-broken.xml');
    file_put_contents($path, '<DsSystemData><Profile_Parameter><parameter_id>x</parameter_id>');

    expect(fn () => app(SysFileReader::class)->read($path, 'broken.xml'))
        ->toThrow(InvalidSysFile::class, 'broken.xml');
});

it('rejects a file that does not exist', function (): void {
    expect(fn () => app(SysFileReader::class)->read(storage_path('framework/testing/syscompare-nope.xml'), 'nope.xml'))
        ->toThrow(InvalidSysFile::class, 'nope.xml');
});
