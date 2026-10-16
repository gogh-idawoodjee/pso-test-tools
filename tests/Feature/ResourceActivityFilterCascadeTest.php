<?php

use App\Services\ResourceActivityFilterService;
use App\Support\PreviewSummaryFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Two resources (R1 in region NORTH, R2 in SOUTH), two activities (A1 at a
 * NORTH location, A2 at a SOUTH one). Filtering to NORTH keeps R1 and A1 and
 * must drop everything that points only at R2 or A2.
 */
function cascadeFixture(): array
{
    return [
        'Region' => [['id' => 'NORTH'], ['id' => 'SOUTH']],
        'Location' => [
            ['id' => 'L1', 'region_id' => 'NORTH'],
            ['id' => 'L2', 'region_id' => 'SOUTH'],
        ],
        'Resources' => [
            ['id' => 'R1', 'location_id_start' => 'L1', 'location_id_end' => 'L1'],
            ['id' => 'R2', 'location_id_start' => 'L2', 'location_id_end' => 'L2'],
        ],
        'Resource_Region' => [
            ['resource_id' => 'R1', 'region_id' => 'NORTH'],
            ['resource_id' => 'R2', 'region_id' => 'SOUTH'],
        ],
        'Resource_Skill' => [
            ['resource_id' => 'R1', 'skill_id' => 'S1'],
            ['resource_id' => 'R2', 'skill_id' => 'S1'],
        ],
        'Resource_Region_Availability' => [
            ['id' => 'RRA1', 'resource_id' => 'R1', 'region_id' => 'NORTH', 'availability_id' => 'AV1'],
            ['id' => 'RRA2', 'resource_id' => 'R2', 'region_id' => 'SOUTH', 'availability_id' => 'AV2'],
        ],
        'Resource_Skill_Availability' => [
            ['resource_id' => 'R1', 'skill_id' => 'S1', 'availability_id' => 'AV3'],
            ['resource_id' => 'R2', 'skill_id' => 'S1', 'availability_id' => 'AV4'],
        ],
        'Availability' => [
            ['id' => 'AV1'], ['id' => 'AV2'], ['id' => 'AV3'], ['id' => 'AV4'],
            ['id' => 'AV5', 'activity_id' => 'A1'],
        ],
        'Resource_Custom_URL' => [
            ['resource_id' => 'R1', 'custom_url_id' => 'U1'],
            ['resource_id' => 'R2', 'custom_url_id' => 'U1'],
        ],
        'Shift' => [
            ['id' => 'SH1', 'resource_id' => 'R1', 'start_datetime' => '2026-10-01T08:00:00', 'end_datetime' => '2026-10-01T16:00:00'],
            ['id' => 'SH2', 'resource_id' => 'R2', 'start_datetime' => '2026-10-01T08:00:00', 'end_datetime' => '2026-10-01T16:00:00'],
        ],
        'Shift_Break' => [
            ['id' => 'SB1', 'shift_id' => 'SH1'],
            ['id' => 'SB2', 'shift_id' => 'SH2'],
        ],
        'Activity' => [
            ['id' => 'A1', 'location_id' => 'L1'],
            ['id' => 'A2', 'location_id' => 'L2'],
        ],
        'Activity_Skill' => [
            ['activity_id' => 'A1', 'skill_id' => 'S1'],
            ['activity_id' => 'A2', 'skill_id' => 'S1'],
        ],
        'Additional_Attribute' => [
            ['id' => 'AA1', 'activity_id' => 'A1', 'name' => 'x'],
            ['id' => 'AA2', 'activity_id' => 'A2', 'name' => 'x'],
            ['id' => 'AA3', 'resource_id' => 'R1', 'name' => 'x'],
            ['id' => 'AA4', 'resource_id' => 'R2', 'name' => 'x'],
        ],
        'Activity_Custom_URL' => [
            ['activity_id' => 'A1', 'custom_url_id' => 'U1'],
            ['activity_id' => 'A2', 'custom_url_id' => 'U1'],
        ],
        'Activity_SLA' => [
            ['activity_id' => 'A1', 'sla_type_id' => 'APPOINTMENT'],
            ['activity_id' => 'A2', 'sla_type_id' => 'APPOINTMENT'],
        ],
        'Activity_Status' => [
            ['activity_id' => 'A1', 'visit_id' => 1],
            ['activity_id' => 'A2', 'visit_id' => 1],
        ],
        'Activity_Group' => [],
        'Skill' => [['id' => 'S1']],
        'Plan_Activity' => [
            ['id' => 'P1', 'activity_id' => 'A1'],
            ['id' => 'P2', 'activity_id' => 'A2'],
        ],
        'Mystery_Table' => [
            ['id' => 'M1', 'activity_id' => 'A1'],
            ['id' => 'M2', 'activity_id' => 'A2'],
        ],
        'Plain_Config' => [
            ['id' => 'C1', 'value' => 'a'],
            ['id' => 'C2', 'value' => 'b'],
        ],
    ];
}

function runCascade(array $data): array
{
    return (new ResourceActivityFilterService($data, ['NORTH']))->filter();
}

it('drops activity child rows for removed activities', function () {
    $filtered = runCascade(cascadeFixture())['filtered'];

    expect(collect($filtered['Activity_Skill'])->pluck('activity_id')->all())->toBe(['A1'])
        ->and(collect($filtered['Additional_Attribute'])->pluck('id')->all())->toBe(['AA1', 'AA3'])
        ->and(collect($filtered['Activity_Custom_URL'])->pluck('activity_id')->all())->toBe(['A1'])
        ->and(collect($filtered['Plan_Activity'])->pluck('id')->all())->toBe(['P1']);
});

it('drops resource child rows for removed resources', function () {
    $filtered = runCascade(cascadeFixture())['filtered'];

    expect(collect($filtered['Resource_Skill_Availability'])->pluck('resource_id')->all())->toBe(['R1'])
        ->and(collect($filtered['Resource_Custom_URL'])->pluck('resource_id')->all())->toBe(['R1']);
});

it('keeps an availability referenced only by a kept Resource_Skill_Availability row, and drops the rest', function () {
    $filtered = runCascade(cascadeFixture())['filtered'];

    expect(collect($filtered['Availability'])->pluck('id')->sort()->values()->all())
        ->toBe(['AV1', 'AV3', 'AV5']);
});

it('does not filter availability by date', function () {
    $data = cascadeFixture();
    $data['Availability'][2]['start_datetime'] = '1999-01-01T00:00:00';
    $result = (new ResourceActivityFilterService(
        $data,
        ['NORTH'],
        startDate: Carbon::parse('2026-10-01'),
        endDate: Carbon::parse('2026-10-02'),
    ))->filter();

    expect(collect($result['filtered']['Availability'])->pluck('id')->all())->toContain('AV3');
});

it('leaves every kept reference resolving, with an empty dangling list', function () {
    $result = runCascade(cascadeFixture());

    expect($result['summary']['dangling'])->toBe([]);
});

it('cascades an unknown table that carries an activity_id', function () {
    $filtered = runCascade(cascadeFixture())['filtered'];

    expect(collect($filtered['Mystery_Table'])->pluck('id')->all())->toBe(['M1']);
});

it('passes a table with no parent key through unchanged', function () {
    $data = cascadeFixture();
    $filtered = runCascade($data)['filtered'];

    expect($filtered['Plain_Config'])->toBe($data['Plain_Config'])
        ->and($filtered['Skill'])->toBe($data['Skill']);
});

it('reports the cascaded tables in the summary', function () {
    $summary = runCascade(cascadeFixture())['summary'];

    expect($summary['activity_skills'])->toBe(['total' => 2, 'kept' => 1, 'skipped' => 1])
        ->and($summary['additional_attributes'])->toBe(['total' => 4, 'kept' => 2, 'skipped' => 2])
        ->and($summary['activity_custom_urls']['skipped'])->toBe(1)
        ->and($summary['resource_skill_availability']['skipped'])->toBe(1)
        ->and($summary['resource_custom_urls']['skipped'])->toBe(1)
        ->and($summary['mystery_table']['skipped'])->toBe(1);
});

it('does not turn the dangling list into a preview card', function () {
    $formatted = PreviewSummaryFormatter::format(runCascade(cascadeFixture())['summary']);

    expect(collect($formatted)->pluck('entity')->all())->not->toContain('Dangling');
});

it('lists a reference that points at nothing kept, with counts only in the log', function () {
    $data = cascadeFixture();
    // A kept Resource_Skill_Availability row pointing at an availability that does not exist.
    $data['Resource_Skill_Availability'][0]['availability_id'] = 'GHOST';
    // Two kept activity statuses fixed to a resource outside the filter would be dangling too.
    Log::spy();

    $summary = runCascade($data)['summary'];

    expect($summary['dangling'])->toHaveCount(1)
        ->and($summary['dangling'][0])->toMatchArray([
            'table' => 'Resource_Skill_Availability',
            'key' => 'availability_id',
            'count' => 1,
            'examples' => ['GHOST'],
        ]);

    Log::shouldHaveReceived('warning')->withArgs(static fn ($message, $context = []) => is_string($message)
        && str_contains($message, 'dangling')
        && ! str_contains(json_encode($context), 'GHOST')
        && ! str_contains($message, 'GHOST'))->once();
});

it('caps dangling examples at five', function () {
    $data = cascadeFixture();
    $data['Resource_Skill_Availability'] = [];
    for ($index = 0; $index < 8; $index++) {
        $data['Resource_Skill_Availability'][] = ['resource_id' => 'R1', 'skill_id' => 'S'.$index, 'availability_id' => 'GHOST'.$index];
    }

    $dangling = runCascade($data)['summary']['dangling'];

    expect($dangling[0]['count'])->toBe(8)
        ->and($dangling[0]['examples'])->toHaveCount(5);
});
