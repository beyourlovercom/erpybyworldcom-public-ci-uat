# Boss Growth Dashboard V1 — Metrics Contract

Status: FROZEN for B0
Date: 2026-09-17

## Canonical truth rules

- Traffic = `traffic_daily_metrics.clicks` inside the selected metric-date window.
- Inquiry = canonical `cases` with `first_inbound_at` inside the selected window.
- Qualified Inquiry = those Inquiry cohort Cases whose current `case_stage = qualified`.
- Opportunity = `sales_opportunities.created_at` inside the selected window.
- Quoted = unique Opportunities with at least one `sales_quotes.created_at` inside the selected window.
- Won = `sales_opportunities.won_at` inside the selected window.
- Won Value = sum of `expected_value_micros` for Won Opportunities, grouped by their canonical currency.
- Revenue is NOT Won Value. Revenue remains unavailable until Revenue Truth V1 exists.

## Attribution outcome cohort

- Channel / Campaign / Landing performance is cohort-based: Cases enter by `first_inbound_at` in the selected window, then show their current downstream Opportunity/Won outcome.
- Attribution coverage denominator is every canonical Inquiry Case in the cohort; missing attribution evidence remains visible as unresolved/missing.
