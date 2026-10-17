# SEO-7 Growth Engine V1

## 0. Goal

Turn Traffic OS / SEO from a migration-oriented evidence workspace into a continuous, quality-gated growth loop:

```
Evidence
  -> Scope / Quality Gate
  -> Growth Queue
  -> Existing Work Order / Content Backlog execution
  -> Publication
  -> T0
  -> 28-day monitoring
  -> T28
  -> Winner / Loser / Mixed signal
  -> next-cycle opportunity recalculation
```

SEO-7 V1 is a **decision engine**. It does not autonomously edit or publish the website.

## 1. Canonical sources of truth

Reuse existing canonical tables. Do not create a parallel task system.

- `seo_keywords` + `seo_keyword_evidence`: evidence library
- `seo_gsc_daily_metrics`: live Query x Page evidence
- `seo_content_backlog`: consolidated new-content truth
- `seo_optimization_work_orders`: execution work orders
- `seo_optimization_publications`: published optimization registry
- `seo_optimization_measurements`: T0 / T28 measurements

## 2. Scope Gate

English Production Growth accepts only exact active root hosts from `business_project_sites`.

For YBY Irrigation this means:

- `ybyirrigation.com`
- `www.ybyirrigation.com`
- `yby-irrigation.com`
- `www.yby-irrigation.com`

Language subdomains such as `fr.`, `es.`, `ar.`, `id.`, `fj.` are **International Migration**, not English Growth.

International Work Orders remain intact; SEO-7 only excludes them from English queue ranking.

## 3. Quality Gate for existing pages

Historical `suggested_action` is evidence, not execution authority.

Every English page cluster must be re-evaluated with scoped GSC metrics.

Minimum evidence:

- at least 20 scoped impressions;
- valid weighted average position.

Decision rules V1:

- Position <= 3:
  - >=100 impressions and CTR <=2% -> P0 `push_top3_ctr`
  - otherwise -> P1 `defend`
- Position <= 10:
  - >=100 impressions and CTR <=2% -> P0 `push_top3_ctr`
  - >=50 impressions and CTR <=3% -> P1 `improve_ctr`
  - otherwise -> P2 `defend`
- Position 11-30:
  - >=50 impressions -> P0 `push_page1`
  - otherwise -> P1 `push_page1`
- Position >30:
  - >=50 impressions -> P2 `optimize_existing`
  - otherwise -> P2 `research_existing`

Below-threshold historical P0/P1 labels are filtered from the Growth Queue.

## 4. New content truth

Do not rank all raw `NEW_CONTENT_P0` keywords as standalone tasks.

SEO-7 only accepts planned rows from the **consolidated** content backlog where the linked keyword still has `suggested_action=NEW_CONTENT_P0`.

Allowed V1 types:

- `blog`
- `commercial_page`
- `comparison_blog`

Legacy Migration Content Plan rows without a live `NEW_CONTENT_P0` keyword signal are kept separate.

## 5. Published-page dedupe

Any URL already present as either:

- `seo_optimization_publications.baseline_url`, or
- `seo_optimization_publications.published_url`

is removed from the actionable Growth Queue and shown under Monitoring.

A published optimization must not be reopened as a new Growth task before its feedback cycle is evaluated.

## 6. 28-day monitoring

Publication lifecycle:

```
monitoring
  -> retest_due
  -> T28
  -> winner | loser | mixed
```

V1 compares **daily-normalized** baseline and T28 rates so a 14-day baseline is not compared directly with a 28-day total.

Signals:

- Winner:
  - average position improves by >=3; or
  - daily impressions improve >=20% without position deterioration; or
  - daily clicks improve >=20% without position deterioration.
- Loser:
  - daily impressions decline >=20% **and**
  - average position deteriorates by >=1.
- Otherwise: Mixed.

These are decision signals, not automatic publishing actions.

## 7. Queue separation

SEO Workspace must visibly separate:

1. **Growth Queue** — English quality-gated actionable opportunities.
2. **Post-publish Monitoring** — published pages waiting for T28 or carrying a T28 signal.
3. **Legacy Migration Content** — migration content plan, not fresh Growth.
4. **International Migration** — non-English language-subdomain Work Orders.
5. **Evidence Library** — full raw keyword/evidence explorer.

## 8. Governance

SEO-7 V1 may:

- read live GSC and keyword evidence;
- rank/cluster recommendations;
- show monitoring and T28 signals;
- feed the next planning cycle.

SEO-7 V1 may **not**:

- auto-edit `ybyirrigation.com`;
- auto-create Product/Category/Blog pages;
- auto-publish;
- auto-close Owner UAT;
- rewrite International Work Orders;
- merge/deploy without the normal PR/UAT gates.

## 9. V1 Roadmap

- G0 Growth Contract ✅
- G1 Scope + Quality Gate
- G2 Growth Queue
- G3 Post-publish Monitoring / Retest Queue
- G4 Winner / Loser / Mixed feedback
- G5 SEO Workspace UI
- G6 Local PHP/Vue/Typecheck/Build regression
- G7 Owner UAT
- G8 PR / merge gate

Future V1.1:

- automatic T28 snapshot scheduling when `retest_after` becomes due;
- cannibalization graph;
- internal-link opportunity graph;
- winning-cluster expansion recommendations;
- country/device segmentation where sample size supports it.
