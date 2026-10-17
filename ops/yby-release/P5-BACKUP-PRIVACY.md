# P5 — Local backup destination and confidentiality gate

Owner-approved scope: **local code/test only**. Production release blocker #100 remains OPEN. The online ERP website is not yet provisioned.

## Observed risk and remediation
- Previous inherited defaults used the separate BYL GitHub backup repository and BYL release-tag namespace.
- The YBY candidate now requires `BACKUP_GITHUB_REPOSITORY` to be configured explicitly; an unset value defaults to empty. Outbound publication and deletion reject repositories other than a dedicated `*/yby-erp-backups` destination. Tags use `yby-erp-backup-`.
- A repository name alone does not prove ownership, account permissions, encryption or private visibility. Before any offsite activity, separately inspect the actual destination, verify private-only permissions, dedicated scoped token, retention, non-public downloads and isolated restore.
- No backup task, scheduler, external upload, production configuration or client-data transfer was enabled by this change.

## Local verification
- `p5-backup-isolation.ps1` statically rejects the inherited BYL config; its approved-source result is not an authorization to enable offsite backups.
- Backup Unit+Feature regression on Windows; the Linux CI executes the POSIX-only archive fixture cases.
- Synthetic negative tests reject accidental BYL repository publication before any gateway/outbound request.
- Confirm a clean exact SHA with `preflight.ps1` and do not package customer exports or credentials.

## Later controlled UAT
A dedicated YBY private repository and its permissions, encrypted snapshot transfer, retention, tamper/incomplete-copy handling, live restored files/database and Owner data-handling review remain unverified and explicitly excluded.
