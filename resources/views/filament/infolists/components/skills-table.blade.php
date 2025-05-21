@php
    $skills = $getRecord()->skills ?? [];
    // Remove the total element if it exists
    $skills = array_filter($skills, function($skill) {
        return !isset($skill['total']);
    });

    // Group skills by category (assuming first part of ID before hyphen is the category)
    $groupedSkills = [];
    foreach ($skills as $skill) {
        $id = $skill['id'] ?? '';
        $category = strpos($id, '-') !== false ? explode('-', $id)[0] : 'Other';

        if (!isset($groupedSkills[$category])) {
            $groupedSkills[$category] = [];
        }

        $groupedSkills[$category][] = $skill;
    }

    // Sort categories alphabetically
    ksort($groupedSkills);
@endphp

<div class="space-y-4">
    <div class="flex justify-between items-center">
        <h3 class="text-base font-medium text-gray-700">Skills ({{ count($skills) }})</h3>
        <div class="relative">
            <input type="text" id="skills-search" class="pl-8 pr-2 py-1 text-sm border border-gray-300 rounded-lg" placeholder="Search skills...">
            <div class="absolute inset-y-0 left-0 flex items-center pl-2 pointer-events-none">
                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
            </div>
        </div>
    </div>

    <div class="overflow-y-auto max-h-96 pr-2 skills-container">
        @forelse($groupedSkills as $category => $categorySkills)
            <div class="mb-4 skill-group">
                <h4 class="text-sm font-semibold text-gray-700 bg-gray-100 p-2 rounded-t-lg">{{ $category }}</h4>
                <div class="border border-gray-200 rounded-b-lg overflow-hidden">
                    <table class="w-full text-sm text-left text-gray-700">
                        <tbody>
                        @foreach($categorySkills as $skill)
                            <tr class="border-b last:border-b-0 hover:bg-gray-50 skill-row">
                                <td class="px-4 py-2 font-medium skill-id">
                                    {{ $skill['id'] ?? '' }}
                                </td>
                                <td class="px-4 py-2 skill-desc">
                                    {{ $skill['description'] ?? '' }}
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="text-center text-gray-500 py-4">
                No skills found
            </div>
        @endforelse
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('skills-search');
        const skillRows = document.querySelectorAll('.skill-row');
        const skillGroups = document.querySelectorAll('.skill-group');

        searchInput.addEventListener('input', function() {
            const searchValue = this.value.toLowerCase();

            skillRows.forEach(row => {
                const id = row.querySelector('.skill-id').textContent.toLowerCase();
                const desc = row.querySelector('.skill-desc').textContent.toLowerCase();
                const isMatch = id.includes(searchValue) || desc.includes(searchValue);

                row.style.display = isMatch ? '' : 'none';
            });

            // Hide empty groups
            skillGroups.forEach(group => {
                const visibleRows = group.querySelectorAll('.skill-row[style="display: none;"]');
                group.style.display = visibleRows.length === group.querySelectorAll('.skill-row').length ? 'none' : '';
            });
        });
    });
</script>
