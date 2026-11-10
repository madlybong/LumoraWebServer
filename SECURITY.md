# Security Policy

## Supported Versions

Only the latest release series of `@astrake/lumora-webserver` receives security fixes.

| Version | Supported |
|---------|-----------|
| v0.1.x  | ✅ (Latest) |

> [!NOTE]
> `@astrake/lumora-webserver` enforces a 12-Gate Automated Compliance Verifier (`lumora-web verify`), including SHA-256 core integrity locking (`lumora-lock.json`), SHA-384 Subresource Integrity (SRI) for compiled CSS/JS/WOFF2 assets, per-request 128-bit CSP nonces, stateless v2 HMAC-SHA256 CSRF tokens, pre-CSRF IP rate limiting, honeypot & `<2s` timing traps, and zero third-party CDN/tracker leaks.

## Reporting a Vulnerability

**Please do not report security vulnerabilities via public GitHub issues.**

If you discover a security vulnerability in `@astrake/lumora-webserver`, please disclose it
responsibly by opening a [GitHub Security Advisory](https://github.com/madlybong/LumoraWebServer/security/advisories/new)
or reaching out directly via the author's GitHub profile at https://github.com/madlybong.

### What to include

- A clear description of the vulnerability and its potential impact.
- Steps to reproduce the issue or a minimal proof-of-concept.
- Any suggested mitigations or fixes, if known.

### Response timeline

- **Acknowledgement:** within 72 hours of a valid report.
- **Status update:** within 14 days.
- **Resolution target:** within 90 days, depending on severity and complexity.

## Disclosure Policy

This project follows a **coordinated disclosure** model. Public disclosure of the
vulnerability details will be made only after a fix has been released, or after the
90-day deadline has elapsed — whichever comes first.

## No Bounty Program

This is an open-source framework project. Responsible reporters will be credited in the release notes.

## Disclaimer

`@astrake/lumora-webserver` is provided "as is" without warranty of any kind.
The author accepts no liability for security incidents arising from the use of this
software.
