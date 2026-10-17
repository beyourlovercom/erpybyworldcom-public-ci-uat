# SEO-6 Batch 3 Spec Contract — 2026-09-18

Status: **VERIFIED / READY_FOR_APPLY**

Governance:
- Legacy `yby-irrigation.com` is read-only evidence.
- New execution target: `ybyirrigation.com`.
- Product pages must render through PDS-V1.
- Do not publish unsupported certification, warranty, payment, MOQ, shipping, free-sample or lead-time claims.
- Do not hotlink legacy CDN media.

## 1. Magic Hose 50/100ft

Verified facts:
- expandable garden hose for garden watering, car washing and household cleaning;
- 50 ft / about 15m and 100 ft / about 30m product classes;
- legacy packing table shows 50 ft hose from about 5m to 15m and 100 ft hose from about 7.5m to 22.5m under water pressure;
- natural latex core tubing is described;
- multifunction spray nozzle is supplied in the legacy product configuration;
- faucet adaptors / US or European connector standards may be configured on request;
- product is designed to contract for compact storage after water pressure is removed.

Do not freeze legacy carton weights/packing as universal product truth without current confirmation.

## 2. Lightweight Fabric Hose

Verified facts:
- woven polyester fabric outer layer with PU inner lining/coating;
- flexible, lightweight and wear-resistant construction;
- legacy page states an ambient working range of about -22 to 100 °F;
- intended uses include agricultural irrigation, garden watering and sump/discharge work;
- legacy examples show dual-layer fabric water hose construction.

Do not publish an exact pressure rating or a universal diameter table until current catalog truth is confirmed.

## 3. Overhead Sprinkler Irrigation

Verified facts:
- greenhouse / nursery overhead micro-sprinkler application;
- common head styles described include refracting atomizing, rotating and cross-atomizing micro-sprinklers;
- five-piece overhead set: sprinkler head, anti-drip device, weight stabilizer, capillary tube and joint;
- working pressure: 0.1–0.25 MPa;
- flow references: 40 / 60 / 80 L/h;
- spray radius: about 1–3m;
- applications include greenhouse/nursery irrigation and evaporative cooling/humidification.

Do not copy warranty, payment, delivery, sample or OEM commercial terms into the product spec.

## 4. Rain Gun Irrigation Sprinkler

Verified facts:
- impact-style rain gun with adjustable / removable nozzle configuration;
- 0–360 degree rotational coverage is described;
- alloy body in the legacy specification table;
- working pressure: 0.2–0.5 MPa;
- connection classes: 50 / 63 / 75mm;
- mobile layflat-hose and fixed riser installation patterns are both shown;
- applications include large-field crops, pasture, landscape and dust-control irrigation.

**Not frozen:** legacy intro and legacy specification table conflict on flow and spray range. Do not publish one exact flow/range claim until current product truth is confirmed.

## 5. Micro Jet Irrigation

Verified facts:
- plastic micro-jet heads / pegs / tubes;
- target/channels spray pattern for ground irrigation;
- flow reference: 60 L/h;
- working pressure: 0.1–0.3 MPa;
- spraying radius: about 1.0–1.5m;
- can be used on spikes or overhead;
- applications include gardens, greenhouse crops, vegetables/farmland, cooling and dust suppression.

Do not copy warranty, payment, delivery, sample or Incoterm claims into product specs.

## PDS / Internal Link Contract

- Magic Hose → Garden Watering / Products / Contact
- Fabric Hose → Layflat Hose family / Irrigation system pages
- Overhead Sprinkler → Plastic Impact Sprinkler / Micro Jet / Sprinkler Hose
- Rain Gun → Plastic Impact Sprinkler / Overhead Sprinkler / Sprinkler Hose
- Micro Jet → Overhead Sprinkler / Plastic Impact Sprinkler / Components

## Media truth

LOCAL new-site media already present:
- Rain Gun: `/wp-content/uploads/2026/06/products-big-rain-gun-water-irrigation-cannon.webp`
- Micro Jet: `/wp-content/uploads/2026/06/products-micro-jet-irrigation.webp`

Media import still required before LOCAL PDS build:
- Magic Hose
- Lightweight Fabric Hose
- Overhead Sprinkler

## Next gate

**SEO-6 Batch 3 PDS Build**
1. import/normalize the three missing media assets to the new-site media library;
2. create five PDS-V1 JSON product data files;
3. render deterministic Product Pages;
4. install on LOCAL at the five governed legacy-compatible routes;
5. run Desktop / Tablet / Mobile gate before any WWW write.
