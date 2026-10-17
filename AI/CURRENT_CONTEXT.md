---
type: current-context
project: YBY ERP
updated: 2026-09-16
status: active-roadmap
---

# Current Context

## Authority and operating model

```text
OWNER: final product/UAT/production-data authority
SOURCE_OF_TRUTH: GitHub / beyourlovercom/erpybyworldcom
LOCAL_DEV_UAT: Windows / https://localerp.ybyworld.com
PRODUCTION_TARGET: Hostinger / https://erp.ybyworld.com
BYL ERP: separate product/runtime/database
CANONICAL_ROADMAP: AI/YBY_ERP_ROADMAP.md
GROWTH_OS_REUSE_CONTRACT: AI/GROWTH_OS_REUSE_CONTRACT.md
```

GitHub `main` is the unique synchronization/source-of-truth path. Local worktrees are implementation sandboxes only and are not roadmap authority until merged.

## Current baseline

```text
BASELINE_MAIN_SHA: c5c92c14afe91a1a0c21deeb3527852dca0ba65c
WINDOWS_LOCAL_FOUNDATION: COMPLETE
YBY_PROJECT_IA: COMPLETE
TRAFFIC_V0_1_TO_V0_4_GOOGLE_ADS: COMPLETE
INQUIRY_OS_M1_CASE_FOUNDATION: COMPLETE
INQUIRY_OS_M2_INTAKE_ASSIGNMENT_IDENTITY: COMPLETE / PR #4
CANONICAL_ROADMAP_SEO_V1_SYNC: COMPLETE / PR #5
GROWTH_OS_REUSE_CONTRACT: FROZEN
HOSTINGER_PRODUCTION: NOT YET CUT OVER
```

Traffic conversion projections remain analytics evidence and must not be treated as canonical Inquiry truth. Inquiry OS M2 now provides the canonical intake/assignment/identity foundation on `cases`.

## Current active execution

```text
NEXT_MAIN_PRODUCT_WORK_PACKAGE:
  Customer OS V1 — global Company + Contact truth / project relationships / identity verification
  status: active local implementation

PARALLEL_BOUNDED_WORK_PACKAGE:
  SEO Traffic V1 — Keyword Library / GSC / Opportunity foundation
  branch: feature/yby-seo-traffic-v1-keyword-library
  status: local foundation in progress; not yet merged
```

## Frozen product architecture

A YBY project is a **1 + N Growth Unit**:

```text
1 = owned independent website
N = acquisition channels
```

Traffic OS supports `ads`, `seo`, `social`, `marketplace`, `referral`, `direct`, and `other`. Alibaba is `marketplace`, not `social`.

Canonical truth ownership:

```text
PROJECT   = BusinessProject
TRAFFIC   = analytics/evidence/projection
INQUIRY   = Case
CUSTOMER  = Company + Contact
SALES     = Opportunity / Quote / Won-Lost
PRODUCT   = Product
```

No Traffic/SEO/Ads/Social adapter may create a second Inquiry, Customer, Sales or Product truth.

### Growth OS reuse freeze

YBY is the first implementation of Growth OS, but Traffic OS / SEO OS are now frozen as **brand-neutral, project-scoped reusable capabilities**. The binding contract is `AI/GROWTH_OS_REUSE_CONTRACT.md`.

Required implications:

```text
Growth OS Core code/contracts may be reused across brands.
YBY ERP and BYL ERP keep separate repositories/runtimes/databases/business data.
No shared Growth OS database between YBY and BYL.
Brand-specific accounts/properties/sites/keywords belong in configuration/adapters.
Reusable core must not hard-code YBY project/domain/provider identifiers.
```

Future BYL adoption is a selective port/cherry-pick with BYL-specific migrations/configuration/UAT and its own production gate.

## Frozen delivery order

```text
Inquiry OS M2 [COMPLETE]
→ Customer OS V1 [ACTIVE]
→ Sales OS V1
→ Attribution V1
→ Boss Growth Dashboard
→ Hostinger Production V1
```

Traffic subtracks may proceed in parallel when bounded and non-conflicting:

```text
Traffic Core
├─ Ads Traffic V1
├─ SEO Traffic V1
└─ Social Traffic V1
```

SEO Keyword/GSC foundations may precede Sales OS, but Article/Page → Inquiry → Revenue attribution is incomplete until canonical Inquiry/Customer/Sales truth exists.

## SEO Traffic V1 frozen requirements

SEO V1 is an SEO decision system, not merely a GSC reporting page.

Required areas:

```text
SEO Overview
Keyword Library
Opportunity Center
Content Backlog
Article Performance
Landing Pages
```

Keyword truth model:

```text
Canonical Keyword
├─ Google Ads evidence
├─ GSC evidence
├─ Alibaba/marketplace evidence
└─ manual keyword-library metadata
```

GSC ingestion must preserve Query × Page × Date × Country × Device plus clicks, impressions, CTR and average position.

Opportunity logic must distinguish at minimum:
- new content / new commercial landing page
- page-one opportunity
- Top-3/CTR opportunity
- existing-page optimization
- topic-cluster merge/backlog

Do not use one-keyword-one-article behavior.

All SEO/Traffic implementation must also satisfy the Growth OS brand-neutrality contract before merge.

## Immediate sequence

```text
1. Continue Customer OS V1 from current GitHub main.
2. Reuse existing Customers/Company capabilities where they fit, but keep one global customer truth.
3. Continue/fix the bounded SEO V1 foundation in its separate worktree without merging worktrees together.
4. Enforce brand-neutral/project-scoped Growth OS design on every SEO/Traffic PR.
5. Sync each bounded branch from GitHub main before merge as needed.
6. Merge only tested bounded PRs.
7. Do not touch Hostinger Production or BYL Production data without the appropriate explicit gate.
```
