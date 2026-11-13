# Security

Report vulnerabilities privately through [GitHub Security Advisories](https://github.com/memberfixstudio-ind/client-brief-desk/security/advisories/new). Do not put client answers, credentials or private records in public issues.

Every API route requires authentication. Staff routes require manage_options; client actions require assignment to that account. WordPress REST cookie authentication checks nonces. The application checks authorization separately from nonce validity.

Optimistic versions and row locks reject stale changes. Brief state and activity entries share an InnoDB transaction. Private notes are not included in client-facing history or exports. Submitted/approved snapshots are application history, not tamper-proof signatures.

Data remains on the WordPress site. Site administrators control backups, retention, user accounts and cache rules. The product does not collect passwords, upload files, send emails, fetch reference URLs or call external services. Use WordPress account security and keep logged-in portal/REST responses out of shared caches.
