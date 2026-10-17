---
type: canonical-roadmap
project: YBY ERP
updated: 2026-09-16
status: active
source_of_truth: GitHub
---

# YBY ERP Canonical Roadmap

## 1. Product definition

YBY ERP is a growth-first Business OS. The V1 goal is to close the loop from acquisition to revenue before selectively adding supply-chain modules.

Canonical business chain:

```text
Project / Product
→ Traffic
→ Inquiry (Case Truth)
→ Customer
→ Opportunity
→ Quote
→ Won / Lost
→ Revenue
```

One project uses a **1 + N Growth Unit** model:

```text
1 = owned independent website
N = acquisition channels
```

Example channels include Google Ads, SEO, Facebook, Instagram, Pinterest, TikTok and Alibaba. Alibaba is modeled as `marketplace`, not `social`.

## 2. Runtime and repository architecture

```text
LOCAL DEV/UAT: Windows / https://localerp.ybyworld.com
PRODUCTION: Hostinger / https://erp.ybyworld.com
REPOSITORY: beyourlovercom/erpybyworldcom
SOURCE OF TRUTH: GitHub main
BYL ERP: separate product/runtime/database; selective code/module reuse only
```

No YBY production feature may depend on `erp.beyourlover.com`. No BYL production business data may be deleted or mutated as part of YBY development without a separate explicit production-data gate.

## 3. Completed baseline

As of `main@6e2a16436518c6f9007af1cd11d68982ae9ad1f3`:

```text
Windows local foundation                         COMPLETE
Independent YBY database/runtime/branding        COMPLETE
Project IA: YBY Irrigation / Bottle / World      COMPLETE
Traffic V0.1–V0.4 / Google Ads boss dashboard    COMPLETE
YBY Irrigation traffic snapshot migration        COMPLETE
Inquiry OS M1 / canonical Case foundation         COMPLETE
```

Traffic conversion projections are analytics evidence only. They must not be converted into fake canonical inquiries.

## 4. Main delivery sequence

### M2 — Inquiry OS V1 intake / assignment / identity foundation
Status: **IN PROGRESS**
Estimated remaining effort: **8–12h**

Scope:
- real inquiry intake adapters
- project/source normalization
- idempotency / duplicate protection
- salesperson assignment
- raw source snapshot/evidence
- customer identity verification fields
- preserve `cases` as canonical inquiry truth

Initial sources may include website forms, WhatsApp and Alibaba/Xiaoman where provider/API access permits.

### M3 — Customer OS V1
Status: planned
Estimated effort: **8–12h**

Scope:
- global Company + Contact truth
- project relationships
- authenticity / scale / buying-history / communication evidence
- duplicate merge/reconciliation
- customer data shared across YBY projects without duplicating customer truth

### M4 — Sales OS V1
Status: planned
Estimated effort: **10–14h**

Scope:
- Opportunity truth
- sales owner
- follow-up timeline
- pipeline stages
- quote linkage
- Won / Lost reason
- revenue linkage

### M5 — Attribution V1
Status: planned
Estimated effort: **6–10h**

Scope:
- first-touch / last-touch attribution
- gclid / UTM / campaign / keyword / landing page evidence
- Article/Landing Page → Inquiry → Opportunity → Won chain
- attribution must reference canonical Inquiry/Customer/Sales truth rather than inventing parallel CRM entities

## 5. Traffic OS V1 — 1 + N architecture

Traffic OS is the unified growth layer for each Business Project.

Top-level traffic types:

```text
ads
seo
social
marketplace
referral
direct
other
```

Frontend V1 groups the main work into:

```text
Traffic Overview
Ads Traffic
SEO Traffic
Social Traffic
```

Marketplace channels such as Alibaba remain separate at the data-model layer even when surfaced in a unified overview.

### T1 — Traffic Core V1
Estimated effort: **4–6h**

- unified channel/source taxonomy
- Website Registry
- project/site/channel relationships
- common Traffic Overview contracts
- preserve existing Google Ads V0.1–V0.4 data

### T2 — Ads Traffic V1
Estimated effort: **4–7h**

- Google Ads adapter becomes first production adapter
- later Meta/TikTok ads adapters
- spend/impressions/clicks/conversions/inquiries/qualified/opportunities/won/revenue
- no Google Ads write operations in V1 unless separately authorized

### T3 — SEO Traffic V1
Estimated effort: **14–22h**
Status: **SEO-5E POST-PUBLISH MEASUREMENT IN PROGRESS**

SEO-5E checkpoint (2026-09-18): SEO-1 through SEO-5D are complete; first 5 product pages are published, Owner WWW UAT PASS, publish date 2026-09-18, T28 retest 2026-10-16. Publication registry and dual-URL T0 measurement foundation are active. External-managed legacy policy: yby-irrigation.com and www.yby-irrigation.com are managed by a separate SEO provider and are read-only from this program. Do not modify legacy content, DNS, Cloudflare, redirects, canonicals, or hosting. Operate and optimize only ybyirrigation.com; legacy GSC data may be retained only as historical/reference evidence.

Completed baseline:
- SEO-1 Keyword Library: merged via PR #8
- SEO-2 Opportunity Center baseline: merged via PR #8
- real seed: 4,464 canonical keywords / 4,622 evidence rows / 210 P0 opportunities

Current branch: `feature/yby-seo-traffic-v1-gsc-content`
Current PR: #11

SEO Traffic V1 is an SEO decision system, not only a GSC report.

Required views:

```text
SEO Overview
Keyword Library
Opportunity Center
Content Backlog
Article Performance
Landing Pages
```

#### T3.1 Keyword Library / canonical keyword truth

YBY Irrigation seed input contains paid-search, GSC, Alibaba and curated keyword evidence. The system must use:

```text
Canonical Keyword
├─ Google Ads evidence
├─ GSC evidence
├─ Alibaba / marketplace evidence
└─ manual keyword-library metadata
```

Do not delete duplicate-looking source rows when they represent different channel evidence.

Required core entities:
- `seo_keywords`
- `seo_keyword_evidence`
- `seo_gsc_daily_metrics`
- `seo_content_backlog`

The keyword/evidence foundation and idempotent `traffic:seo-keywords:import` command are merged to GitHub `main` via PR #8. SEO-3 now adds the live read-only GSC Query×Page ingestion path on a bounded branch.

#### T3.2 GSC connector

GSC ingestion must preserve at least:

```text
query
page
date
country
device
clicks
impressions
ctr
average_position
```

`average_position` is a GSC metric, not an exact universal SERP rank.

#### T3.3 SEO Opportunity Engine

The engine combines:

```text
Paid Evidence
× Organic Evidence
× Commercial Intent
× Content Gap / Target-page fit
```

Execution pools:
- `NEW_CONTENT_P0`: proven paid/commercial demand with weak/no organic coverage
- `PAGE1_P0`: strong commercial terms roughly in GSC positions 11–30
- `TOP3_CTR_P0`: page-one terms with material impression/CTR upside
- lower-priority optimization/research/backlog pools

The system must distinguish:
- new Blog article
- new Commercial Landing Page
- optimize Existing Page
- merge into existing Topic Cluster

It must not implement one-keyword-one-article behavior.

#### T3.4 Content backlog

For every proposed article/page store:
- topic cluster
- target keyword(s)
- search intent
- product/category relationship
- country/language when relevant
- commercial priority
- suggested target type
- workflow status: planned / writing / published / optimize / retired

Primary business question: **what should we write/optimize next?**

#### T3.5 Article / page performance

Final SEO performance chain:

```text
Article / Landing Page
→ GSC Impressions
→ GSC Clicks
→ Organic Session
→ Inquiry
→ Qualified Inquiry
→ Opportunity
→ Won
→ Revenue
```

GSC alone cannot prove which article caused an inquiry; final attribution requires website/session evidence plus canonical ERP Inquiry truth.

### T4 — Social Traffic V1
Estimated effort: **10–16h**

Initial channels:
- Facebook
- Instagram
- Pinterest
- TikTok

Model:

```text
Account
→ Post / Video / Pin
→ Impression / View
→ Engagement
→ Outbound Click
→ Landing Page
→ Inquiry
→ Opportunity / Won
```

Follower/view counts are supporting metrics, not business success truth.

## 6. Product OS / Boss Dashboard / Production

### Product OS YBY V1
Estimated effort: **6–10h**
- global Product master
- project/category visibility
- reuse selected BYL capabilities without importing BYL-specific business truth

### Boss Growth Dashboard V1
Estimated effort: **4–7h**
Unified project view:

```text
Channel | Spend | Traffic | Inquiry | Qualified | Opportunity | Won | Revenue
```

### Hostinger Production V1
Estimated effort: **6–10h**
- `erp.ybyworld.com`
- independent database/env/storage/session namespace
- SSL
- queue/scheduler where required
- backups/rollback
- exact approved release SHA
- Production UAT

### BYL → YBY selective migration
Estimated effort: **5–8h**
- migrate selected reusable modules/master data only
- no wholesale BYL database clone
- real business data moves only when explicitly identified as YBY-owned/shared and gated

### Final RBAC / hardening / Owner UAT
Estimated effort: **6–10h**

## 7. Production V1 Closure — entered 2026-09-18

Core business truth is now established and merged: Foundation, Traffic, Inquiry, Customer, Sales, Attribution, Boss Growth Dashboard, and canonical Project VI. The program is therefore in **Production V1 Closure**, not feature-foundation mode.

Closure dependency order:

```text
P1 Traffic Runtime / GSC Automatic Sync
→ P2 YBY Product OS / Global Product Master
→ P3 Revenue / Order Truth Contract
→ P4 Hostinger erp.ybyworld.com Production Runtime
→ P5 Selective Legacy Connectors / Reconciliation
→ P6 Production UAT / Hardening / V1 Close
```

SEO execution may continue in parallel but must not block the ERP closure path.

### P1 — Traffic Runtime
- Google Ads existing sync remains canonical Ads ingestion.
- GSC existing `traffic:seo-gsc:sync` remains the canonical read-only Search Console ingestion command.
- Add scheduler-gated automatic GSC sync: daily, 7-day rolling re-read, 2-day data lag by default, idempotent apply, no guessed SEO truth.
- Scheduler is opt-in through environment configuration; credentials remain external to Git.

### P2 — Product OS
Create YBY Global Product Master and project relation using selective reuse from mature BYL Product OS. Do not copy BYL business data wholesale.

### P3 — Revenue / Order Truth
Freeze the canonical distinction `Won Value != Revenue`. Add Revenue/Order truth before Growth Dashboard may display actual revenue.

### P4 — Production Runtime
`localerp.ybyworld.com` on Windows remains Dev/UAT. `erp.ybyworld.com` on Hostinger becomes Production with isolated DB, secrets, queue, scheduler, backup, health and rollback.

### P5 — Legacy Connectors
Kenya ERP is a read-only legacy regional source first. Prefer official OpenAPI read-only for Customer/Product/Historical Sales reconciliation. No bidirectional writes in V1.

### P6 — Final UAT
RBAC, salesperson accounts, backup restore, audit, runtime alarms, data isolation, owner browser UAT, then V1 close.

## 8. Current truth boundaries

```text
PROJECT   = BusinessProject truth
TRAFFIC   = analytics/evidence/projection
INQUIRY   = Case truth
CUSTOMER  = Company + Contact truth
SALES     = Opportunity / Quote / Won-Lost truth
PRODUCT   = Product truth
REVENUE   = future canonical Order/Revenue truth; never inferred from Won Value
```

No Traffic/SEO/Ads/Social adapter may create a second Inquiry/Customer/Sales truth.

## 9. Current closure branch

```text
start baseline: ba95de7f833e3306a227289c678d42fb582d3007
branch: feature/production-v1-closure-p1-gsc-auto-sync
worktree: D:\ai\localerp.ybyworld.com-prod-v1-p1-gsc
scope: P1 GSC automatic sync only
```

Parallel SEO branches may advance `main`; this closure branch must reconcile onto fresh GitHub `main` before merge.

## 10. Remaining V1 effort estimate

From Production V1 Closure entry, remaining effective engineering effort is approximately **25–40 hours**, dominated by Product OS selection/reuse, Revenue Truth, Hostinger runtime, connector reconciliation, and final UAT.

## 11. Immediate next execution

```text
1. Finish P1 GSC Automatic Sync V1 and local scheduler UAT.
2. Merge bounded P1 PR after exact-head validation.
3. Start P2 YBY Product OS from fresh GitHub main.
4. Freeze Revenue/Order Truth contract before exposing Revenue in Growth Dashboard.
5. Provision erp.ybyworld.com only after P2/P3 data contracts are stable.
6. Keep Kenya ERP integration read-only until canonical YBY truth is stable.
```
