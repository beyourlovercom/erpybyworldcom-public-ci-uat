# SEO-6 Batch 3 Selection — 2026-09-18

Status: **FROZEN / READY_FOR_SPEC**

Operating rule:
- Legacy `yby-irrigation.com` remains external-managed and read-only.
- New execution target is `ybyirrigation.com`.
- Product pages use YBY Product Detail System V1.
- Do not create one page per keyword when multiple work orders belong to one product/topic cluster.

## Selected new Product Pages

| Work Order | Primary keyword | Legacy evidence URL | Decision |
|---|---|---|---|
| 19 | magic hose | `/garden-watering/magic-hose-50-100ft.html` | New PDS Product Page |
| 20 | fabric hose pipe | `/pipes-pipe-fittings/lightweight-fabric-hose.html` | New PDS Product Page |
| 21 | overhead sprinklers | `/spray-irrigation/overhead-sprinkler-irrigation.html` | New PDS Product Page |
| 56 | rain gun | `/spray-irrigation/rain-gun-irrigation-sprinklers.html` | New PDS Product Page |
| 62 | micro jet irrigation | `/micro-irrigation-system/micro-jet-irrigation.html` | New PDS Product Page |

## Consolidation decisions

- WO #4 `drip tape irrigation` → existing published Drip Tape page (WO #2).
- WO #8 `drip irrigation system parts` → existing published Components page (WO #9).
- WO #29 / #87 / #88 `laser irrigation / rain hose / rain pipe` → hold as supporting evidence for the published Sprinkler Hose family; do not create three pages now.
- WO #72 / #91 `irrigation gun / gun sprinkler` → supporting evidence for WO #56 Rain Gun.
- WO #7 `micro sprinkler heads` → supporting evidence for WO #62 Micro Jet Irrigation.
- WO #51 `canvas hose` remains Article/Comparison backlog.
- WO #23 `china drip irrigation system` remains Category Hub backlog.
- WO #41 `farm irrigation system design` remains Landing/Guide backlog.

## Evidence ordering

Selected Product Pages are chosen from remaining P0 work orders after excluding:
1. already-published product families;
2. cross-language legacy subdomains;
3. overlapping/cannibalizing target pages;
4. non-product page types that should wait for Article/Landing templates.

Priority evidence:
- WO19 score 99.45 / 51 impressions / avg position 19.73
- WO20 score 48.75 / 25 impressions / avg position 16.20
- WO21 score 37.05 / 19 impressions / avg position 10.05
- WO56 score 37.05 / 19 impressions / avg position 8.74
- WO62 score 31.20 / 16 impressions / avg position 5.50

## Next gate

**SEO-6 Batch 3 Spec Verification**
- read the five legacy evidence pages;
- extract only verified current product facts;
- confirm current media/product truth where available;
- draft SEO title, H1, intro, specs, applications, FAQ and internal links;
- then render all five through PDS-V1 on LOCAL before any WWW write.
