import hljs from 'highlight.js/lib/core';
import json from 'highlight.js/lib/languages/json';
import 'highlight.js/styles/github-dark.css';

// Register the JSON language
hljs.registerLanguage('json', json);
hljs.configure({ ignoreUnescapedHTML: true });
window.hljs = hljs;

function highlightCode() {
    const codeBlocks = document.querySelectorAll('pre code.language-json');

    codeBlocks.forEach((block) => {
        const content = block.textContent.trim();
        // console.log('Current content:', content);
        // console.log('Content length:', content.length);
        // console.log('Is it just "null"?', content === 'null');

        if (content.length > 4 && content !== 'null') { // Must be more than just "null"
            console.log('Highlighting!');
            delete block.dataset.highlighted;
            hljs.highlightElement(block);
        } else {
            // console.log('Skipping highlight - waiting for real content');
        }
    });
}

document.addEventListener('DOMContentLoaded', highlightCode);
document.addEventListener('livewire:updated', () => {
    // console.log('Livewire updated - checking content...');
    setTimeout(highlightCode, 100);
});

// Add this new event listener for the custom event
document.addEventListener('json-updated', () => {
    // console.log('Custom json-updated event fired!');
    setTimeout(highlightCode, 100);
});
