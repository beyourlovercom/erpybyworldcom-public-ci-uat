# P6 Local-only release readiness

No Production permission. Owner accepted P5 Local UAT at main 3192297acbc655974020fcf61de9fa79e3785aaa.

Release gates: G0 exact GitHub SHA and clean tree; G1 YBY-only runtime (never use BYL ops/production); G2 dedicated YBY backup destination and production recovery Issue #100; G3 separately authorized Hostinger synthetic staging, currently absent; G4 auth/RBAC and separate secrets/storage; G5 real-adapter synthetic restore/migration and rollback; G6 explicit Owner exact-head Production approval.

The local dry run cannot provision servers, migrate data, enable remote backup or approve a Production release.
