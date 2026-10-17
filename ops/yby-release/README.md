# YBY ERP P4 — Local-only release readiness

This is a preparation checklist, NOT a deployment script or authorization. Active runtime: Windows localerp.ybyworld.com. Future erp.ybyworld.com is NOT provisioned.

- Source of code truth: GitHub main pinned immutable SHA; clean independent release candidate.
- Do not reuse ops/production, which describes the unrelated BYL ERP Mac runtime.
- Use synthetic fixtures only. Never package real customer data, exports, .env, credentials, or private storage in a release.
- Separate webroot public/, secret config, shared private storage, dedicated DB and rollback snapshot after separately approved hosting provisioning.
- A hosted ERP stores uploaded data on the host; real customer migration requires independent Owner consent.
- Release gate: Local tests → Hostinger read-only account capability verification → authorized isolated synthetic staging → restore/rollback → Owner UAT → separate Production release authorization.
- Backup Production blocker #100 remains OPEN; never enable external connectors, scheduler or Production writes under Local-only approval.
