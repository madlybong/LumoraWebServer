<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

return [
    'slug'     => 'hello-lumora-web',
    'title'    => 'Introducing Lumora WebServer 0.2.0: Admin Console, Hybrid CMS & Tailwind v4 Pipeline',
    'date'     => '2026-11-16',
    'status'   => 'published',
    'author'   => 'Lumora Maintainers',
    'category' => 'Release',
    'tags'     => ['php-8.2', 'tailwind-v4', 'sqlite-wal', 'sri', 'admin-console'],
    'image'    => '/public/brand/blog-cover.svg',
    'summary'  => 'A full walkthrough of the Lumora WebServer 0.2.0 reference starter featuring locally compiled Tailwind CSS v4, adaptive light/dark mode, hybrid SQLite + flat-file CMS parity, and the opt-in Vue 3 + CodeMirror 6 Admin Console.',
    'body'     => '<p><strong>Lumora WebServer</strong> pairs a zero-dependency PHP 8.2+ security micro-kernel with deterministic Tailwind CSS v4 builds, SHA-384 Subresource Integrity lockfiles, and an embedded Vue Admin Console.</p>'
        . '<h2>What Ships Out of the Box</h2>'
        . '<ul>'
        . '<li><strong>Adaptive Light + Dark Design System:</strong> Built with Zinc &amp; Electric Blue tokens matching the Admin Console, CSS <code>light-dark()</code>, and CSP-nonced first-paint theme persistence.</li>'
        . '<li><strong>Hybrid Flat-File &amp; SQLite CMS:</strong> Publish documentation tracks, blog articles with Atom syndication (<code>/blog/feed.xml</code>), SHA-256 releases, and dynamic contact channels interchangeably from PHP/Markdown files or SQLite.</li>'
        . '<li><strong>12-Gate Continuous Verification:</strong> Every production build validates strict types, bootstrap guards, clean HTML structure, SRI matching hashes, discovery feeds, and isolated security boundaries across live ephemeral servers.</li>'
        . '</ul>'
        . '<blockquote><p>Run <code>bunx lumora-web dev</code> locally to launch both the styled starter application and a pre-configured <code>/admin/</code> setup workflow.</p></blockquote>',
];
