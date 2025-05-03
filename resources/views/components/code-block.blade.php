<style>
    .code-container {
        position: relative;
        border-radius: 0.5rem;
        background-color: #1a1d23;
        color: white;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        overflow: hidden;
    }

    .code-copy-button {
        position: absolute;
        top: 8px;
        right: 8px;
        background-color: rgba(255, 255, 255, 0.1);
        color: rgba(255, 255, 255, 0.8);
        border: none;
        border-radius: 4px;
        padding: 5px 10px;
        font-size: 12px;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        gap: 4px;
        z-index: 10;
    }

    .code-copy-button:hover {
        background-color: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .code-copy-button svg {
        width: 14px;
        height: 14px;
    }
</style>

@props([
    'code' => '',
    'language' => 'php',
    'highlightLines' => [],
])

@php
    use Spatie\ShikiPhp\Shiki;

    $highlighted = Shiki::highlight(
        code: $code,
        language: $language,
        highlightLines: $highlightLines
    );

    // Generate a unique ID for each code block

@endphp

<div class="code-container">
    <button
        id="copyButton"
        class="code-copy-button"
        onclick="copyCodeToClipboard()"
    >
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
        </svg>
        <span>Copy</span>
    </button>

    <div class="shiki p-5 overflow-x-auto">
        {!! $highlighted !!}
    </div>
</div>

<textarea id="codeText" style="position: absolute; left: -9999px;">{!! $code !!}</textarea>

<script>
    function copyCodeToClipboard() {
        const codeText = document.getElementById('codeText');
        const copyButton = document.getElementById('copyButton');

        if (!codeText || !copyButton) return;

        // Select the text
        codeText.select();
        codeText.setSelectionRange(0, 99999);

        try {
            // Copy the text
            document.execCommand('copy');

            // Update button text/icon to show success
            const originalHTML = copyButton.innerHTML;
            copyButton.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="text-green-500">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <span>Copied!</span>
                `;

            // Reset after 2 seconds
            setTimeout(() => {
                copyButton.innerHTML = originalHTML;
            }, 2000);
        } catch (err) {
            console.error('Failed to copy:', err);
        }
    }
</script>
