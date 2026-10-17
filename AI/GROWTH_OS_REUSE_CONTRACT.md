---
type: frozen-architecture-contract
project: YBY ERP / reusable Growth OS
updated: 2026-09-16
status: frozen
source_of_truth: GitHub
---

# Growth OS Reuse Contract

## Decision

YBY is the first implementation of Growth OS, but Traffic OS / SEO OS must remain brand-neutral and project-scoped so the same capability can later be selectively ported to BYL ERP and future brands.

## Frozen architecture

```text
Growth OS Core
├─ Traffic Core
├─ Ads Traffic
├─ SEO Traffic
├─ Social Traffic
├─ Keyword Library
├─ Opportunity Engine
├─ Content Backlog
├─ Attribution
└─ Growth Dashboard
```

Brand/product runtimes remain independent:

```text
YBY ERP  -> independent repo/runtime/database/data
BYL ERP  -> independent repo/runtime/database/data
```

Code/contracts may be reused. Runtime databases and brand business data must not be shared.

## Mandatory project scoping

Reusable Growth OS data and services must scope through generic identifiers/configuration such as:

- `business_project_id`
- site / website registry identity
- traffic source account identity
- GSC property identity
- analytics property identity
- social account identity
- keyword library identity

Do not hard-code `yby-irrigation`, `ybyirrigation.com`, YBY Google accounts, fixed GSC properties, or brand-specific keyword seeds into reusable domain/application logic.

## Truth boundaries

Growth OS remains analytics/evidence/projection. It may reference canonical truths but must not create competing truths.

```text
PROJECT   = BusinessProject
TRAFFIC   = analytics/evidence/projection
INQUIRY   = Case
CUSTOMER  = Company + Contact
SALES     = Opportunity / Quote / Won-Lost
PRODUCT   = Product
```

## BYL reuse path

When the YBY implementation is stable, reuse in BYL must be selective:

```text
YBY implementation validated
-> reusable Growth OS contract stabilized
-> selective port/cherry-pick into BYL repo
-> BYL-specific migrations/configuration
-> BYL-specific GSC/Ads/GA4/Social connectors
-> BYL-local UAT
-> separate BYL production gate
```

No direct shared Growth OS database between YBY and BYL.

## BYL-specific future advantage

The reusable core must support BYL extending the generic chain to:

```text
Keyword / Article / Landing Page
-> Organic or Paid Traffic
-> Product / Checkout
-> Order
-> Revenue
-> Gross Profit
```

This uses BYL's own canonical Order/Accounting truth and must not be embedded as YBY-specific behavior in the shared core.

## Implementation rule

All new Traffic OS / SEO OS work from this point forward must pass a brand-neutrality review before merge. Brand-specific behavior belongs in configuration/adapters, not the reusable core.
