# Getting Started with `@astrake/lumora-webserver`

> [!NOTE] `@astrake/lumora-webserver` provides a locked, headless PHP 8.2+ micro-kernel paired with a Tailwind CSS v4 brand layer compiler and a 12-Gate Automated Compliance Verifier.

## Core Lifecycle Commands

1. `lumora-web sync`: Copies the locked PHP core into `core/` and writes `lumora-lock.json`.
2. `lumora-web css`: Compiles `lumora-base.css` + `css/brand-theme.css` + `css/brand.css` into `public/assets/css/lumora.min.css`.
3. `lumora-web build`: Packages a 100% standalone `dist/` directory with physical `<route>/index.php` stubs.
4. `lumora-web verify`: Executes all 12 compliance gates against source, live HTTP, and `dist/`.

| Gate | Responsibility |
| :--- | :--- |
| Gate 01 | SHA-256 Core Lock Integrity |
| Gate 09 | Pre-CSRF Rate-Limit & SQLite WAL Smoke Test |
| Gate 12 | Zero-Rewrite `dist/` Server Verification |
