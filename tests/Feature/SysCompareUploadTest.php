<?php

use App\Models\User;
use App\Support\SysCompare\Storage\SysCompareStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\SysFileBuilder;

beforeEach(function (): void {
    Storage::fake('sys-compare');
});

function sysCompareUploadUrl(): string
{
    return route('filament.app.sys-compare.uploads.store');
}

function sysCompareUser(int $id = 101, string $email = 'tester@mac.com'): User
{
    return User::factory()->make(['id' => $id, 'email' => $email]);
}

function uploadXml(string $name, string $content): TestResponse
{
    return test()->post(sysCompareUploadUrl(), ['file' => UploadedFile::fake()->createWithContent($name, $content)], ['Accept' => 'application/json']);
}

it('requires a signed-in user', function (): void {
    $this->post(sysCompareUploadUrl(), [], ['Accept' => 'application/json'])->assertUnauthorized();
});

it('refuses a user who cannot access the panel', function (): void {
    $this->actingAs(sysCompareUser(email: 'someone@elsewhere.example'));

    uploadXml('prod.xml', SysFileBuilder::make()->toXml())->assertForbidden();
});

it('stores a valid sys file on the private disk and returns its id', function (): void {
    $this->actingAs(sysCompareUser());

    $response = uploadXml('prod.xml', SysFileBuilder::make()->parameter('DEFAULT', 'A', '1')->toXml());

    $response->assertCreated()->assertJsonStructure(['id', 'name', 'size'])->assertJsonPath('name', 'prod.xml');

    expect(app(SysCompareStorage::class)->uploadPath(101, $response->json('id')))->not->toBeNull();
});

it('rejects a file that is not a DsSystemData export and does not keep it', function (): void {
    $this->actingAs(sysCompareUser());

    uploadXml('other.xml', '<dsScheduleData><Secret>do-not-echo</Secret></dsScheduleData>')
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'other.xml') && str_contains($message, 'DsSystemData') && ! str_contains($message, 'do-not-echo'));

    expect(app(SysCompareStorage::class)->pendingUploadCount(101))->toBe(0);
});

it('rejects a file that is not XML at all', function (): void {
    $this->actingAs(sysCompareUser());

    uploadXml('broken.xml', 'this is not xml')->assertUnprocessable();

    expect(app(SysCompareStorage::class)->pendingUploadCount(101))->toBe(0);
});

it('rejects the wrong file type', function (): void {
    $this->actingAs(sysCompareUser());

    uploadXml('notes.txt', '<DsSystemData />')->assertUnprocessable()->assertJsonPath('message', 'notes.txt: please choose a .xml file.');
});

it('rejects a file over the size limit with a plain-English message', function (): void {
    config(['sys-compare.max_file_kilobytes' => 1]);
    $this->actingAs(sysCompareUser());

    uploadXml('big.xml', '<DsSystemData>'.str_repeat('x', 3000).'</DsSystemData>')
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'big.xml') && str_contains($message, 'larger than'));
});

it('stops accepting uploads when too many are pending', function (): void {
    config(['sys-compare.max_pending_uploads' => 1]);
    $this->actingAs(sysCompareUser());

    uploadXml('a.xml', SysFileBuilder::make()->toXml())->assertCreated();
    uploadXml('b.xml', SysFileBuilder::make()->toXml())->assertStatus(429);
});

it('accepts a definitions csv without treating it as a sys file', function (): void {
    $this->actingAs(sysCompareUser());

    $response = test()->post(sysCompareUploadUrl(), [
        'kind' => 'definitions',
        'file' => UploadedFile::fake()->createWithContent('ParamDefinitions.csv', "Parameter,Definition,Basis\nFoo,Does foo,\n"),
    ], ['Accept' => 'application/json']);

    $response->assertCreated();
    expect(app(SysCompareStorage::class)->uploadPath(101, $response->json('id')))->not->toBeNull();
});
