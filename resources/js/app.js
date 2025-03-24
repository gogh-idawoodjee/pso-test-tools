import hljs from 'highlight.js';
import 'highlight.js/styles/github.css'; // You can change this theme

document.addEventListener("DOMContentLoaded", function () {

    document.querySelectorAll("pre code").forEach((block) => {
        hljs.highlightElement(block);
    });

});
