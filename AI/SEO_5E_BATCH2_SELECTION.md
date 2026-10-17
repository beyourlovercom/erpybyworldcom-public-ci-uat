# SEO-5E Batch 2 Selection — 2026-09-18

Status: **FROZEN / READY_FOR_SPEC**

Operating rule:
- Legacy `yby-irrigation.com` is externally managed and read-only.
- Legacy Ads/GSC/marketplace data is evidence only.
- New execution target is `ybyirrigation.com`.
- Legacy and new-domain KPI series remain separate.

## Selected pages

| Work Order | Primary keyword | Legacy evidence URL | New target URL |
|---|---|---|---|
| 1 | drip irrigation kit | `www.yby-irrigation.com/drip-irrigation-system/drip-irrigation-kit-for-1-hectare.html` | `ybyirrigation.com/drip-irrigation-system/drip-irrigation-kit-for-1-hectare.html` |
| 3 | drip irrigation pipe | `www.yby-irrigation.com/drip-irrigation-system/farm-16mm-drip-irrigation-pipe-tube-hose-drip.html` | `ybyirrigation.com/drip-irrigation-system/farm-16mm-drip-irrigation-pipe-tube-hose-drip.html` |
| 5 | solar irrigation system | `www.yby-irrigation.com/farm-irrigation-system/solar-irrigation-system.html` | `ybyirrigation.com/farm-irrigation-system/solar-irrigation-system.html` |
| 9 | components of drip irrigation system | `www.yby-irrigation.com/drip-irrigation-system/components-of-drip-irrigation-system.html` | `ybyirrigation.com/drip-irrigation-system/components-of-drip-irrigation-system.html` |
| 89 | plastic sprinkler | `www.yby-irrigation.com/spray-irrigation/plastic-impact-sprinklers.html` | `ybyirrigation.com/spray-irrigation/plastic-impact-sprinklers.html` |
| 16 | sprinkler hose with holes | `www.yby-irrigation.com/spray-irrigation/sprinkler-hose-with-holes.html` | `ybyirrigation.com/spray-irrigation/sprinkler-hose-with-holes.html` |

## Evidence highlights

- **Drip irrigation kit**: strongest commercial proof in this batch. Legacy Google Ads evidence: 751 impressions / 137 clicks / 2 conversions; legacy GSC seed evidence: 7,602 impressions / 13 clicks.
- **Drip irrigation pipe**: legacy Google Ads evidence 53 impressions / 7 clicks; legacy GSC seed evidence 3,505 impressions / 6 clicks; page-level GSC position about 8.07.
- **Solar irrigation system**: legacy GSC seed evidence 5,840 impressions / 7 clicks; page-level GSC position about 25.94; Alibaba evidence also present.
- **Components of drip irrigation system**: legacy GSC seed evidence 2,432 impressions / 6 clicks; page-level position about 12.31; Google Ads evidence also present.
- **Plastic sprinkler**: page-level position about 4.87 with zero clicks in the current work-order baseline; useful CTR/content-fit opportunity.
- **Sprinkler hose with holes**: legacy GSC seed evidence 3,447 impressions / 17 clicks; page-level position about 11.50.

## Coverage check

The six target URLs currently have no real Product Page on `ybyirrigation.com`.
Current requests fall through to a generic soft-200 fallback after a trailing-slash redirect:
- generic title `YBY IRRIGATION`
- no H1
- no canonical
- no Product Page marker

This means Batch 2 may safely create real governed pages at these paths.

## Next gate

**SEO-5E-B2 Spec Verification**
1. Read the six legacy pages and extract only verified product facts/specs.
2. Cross-check against current product/media truth where available.
3. Draft Title/H1/intro/sections/FAQ/internal-links for the new site.
4. Do not copy unsupported certifications/specifications.
5. Then move each work order from `ready_for_spec` → `ready_for_apply`.
