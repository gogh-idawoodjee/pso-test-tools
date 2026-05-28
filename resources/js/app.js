import hljs from 'highlight.js/lib/core';
import json from 'highlight.js/lib/languages/json';
import 'highlight.js/styles/github-dark.css';

hljs.registerLanguage('json', json);
hljs.configure({ ignoreUnescapedHTML: true });
window.hljs = hljs;
