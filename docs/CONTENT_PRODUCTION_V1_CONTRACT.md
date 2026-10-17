# Content Production V1 — Architecture Contract

Status: C0 frozen for implementation  
Canonical audit baseline: `erpybyworldcom/main@16336765e85e3687e0dbf1bc1d25b80624677b06`  
Scope: reusable Content Production Core implemented in YBY ERP first, portable to BYL ERP after real YBY canary.

## 1. Product boundary

Content Production is a new domain module. It owns article production workflow after a content opportunity has been selected.

It does **not** own:
- SEO ranking, keyword scoring, Growth Queue selection, Migration planning, or T28 evaluation;
- Product Canonical / Product Detail System work;
- WordPress author accounts or writer-facing WordPress access;
- an ERP rich-text editor;
- group employee workspaces in Jutdo.

YBY SEO-7 remains the decision engine and canonical owner of SEO evidence and opportunity selection. Content Production consumes selected article opportunities through an adapter.

## 2. Repository boundary

The implementation target is `App/Modules/Content`, following the repository's existing module layering:
- `Application`: workflow, DTOs, contracts, orchestration;
- `Infrastructure`: Eloquent persistence and external provider implementations;
- `Experience`: HTTP/console/UI entry points;
- `Providers`: Laravel service registration.

Core Application and Domain code must not import concrete classes from `Traffic`, `Products`, YBY-specific project services, or website-specific publishing code.

YBY-specific integration belongs in adapters outside the portable workflow logic.

## 3. Canonical ownership

### Traffic / SEO owns
- `seo_keywords` and keyword evidence;
- `seo_gsc_daily_metrics`;
- `seo_content_backlog`;
- Growth Queue / Migration queue decisions;
- post-publish SEO measurement and T28 feedback.

### Content owns
- Article Base copied from an accepted source opportunity;
- authoring-mode selection;
- writer assignment / claim lifecycle;
- guideline snapshot;
- Google document source metadata;
- immutable submitted snapshots;
- editorial versions;
- layout / publishing workflow state;
- Content audit events.

Content records reference source records by `source_system + source_ref`; Content must not mutate SEO selection truth except through an explicit host adapter or later callback contract.

## 4. Article Base

A Content Article is created only after source routing confirms an article-like type.

Minimum portable fields:
- source_system / source_ref;
- project_key;
- brand_key;
- target_site;
- language;
- content_type;
- primary_keyword;
- supporting_keywords;
- search_intent;
- topic_cluster;
- title_direction;
- canonical_path;
- content_objective;
- target_audience;
- evidence_summary;
- internal_link_targets;
- reference_sources;
- prohibited_claims;
- recommended_structure;
- recommended_word_count;
- authoring_mode;
- workflow_status.

Article types in V1: `blog`, `guide`, `comparison`. Product Canonical routes are excluded.

## 5. Authoring modes

`authoring_mode` is unset when the Article Base is first created.

The Authoring Mode Gate chooses exactly one:
- `ai`;
- `human`.

Mode may be changed only while no authoring work has begun. Once a writer claim, AI draft, or submitted snapshot exists, mode changes require an explicit reset/cancel workflow and must be audited.

AI and Human modes converge at Editorial.

## 6. Workflow state machine

Canonical V1 states:

```
article_base_ready

AI:
  ai_writing
  draft_ready

Human:
  waiting_for_writer
  writer_claimed
  writing
  doc_submitted
  doc_imported

Shared:
  editorial_review
  editorial_ready
  layout_ready
  owner_uat
  published
  monitoring

Exceptional:
  blocked
  returned_for_revision
  cancelled
```

Normal transitions:

```
article_base_ready -> ai_writing -> draft_ready -> editorial_review
article_base_ready -> waiting_for_writer -> writer_claimed -> writing
writing -> doc_submitted -> doc_imported -> editorial_review
editorial_review -> editorial_ready -> layout_ready -> owner_uat
owner_uat -> published -> monitoring
```

`returned_for_revision` must retain the version/snapshot that was reviewed. Return-to-writing behavior is explicit, never destructive.

`blocked` records a reason and retry context. `cancelled` is terminal unless a new Article Production record is intentionally created.

Owner UAT cannot be auto-passed by Content Core.

### Publication quality and indexing gate

The two authoring modes remain distinct through their source-creation stages and converge at Editorial. Publication approvals therefore follow the authoring source rather than fabricating a Writer approval for AI-authored content:

- Human mode: `Writer PASS + Fact PASS + SEO PASS + Owner UAT`.
- AI mode: `Fact PASS + SEO PASS + Owner UAT`; `Writer PASS` is not applicable because no human writer owns the source draft.

Every provider Draft must request `noindex`. Final Publish must request `index`. ERP must fail closed if the WordPress provider read-back does not confirm the requested indexing state. A Draft may never become indexable merely because it exists in WordPress.

## 7. Persistence direction

V1 uses dedicated Content tables rather than extending `seo_content_backlog`.

Required C1 entities:
- `content_articles`;
- `content_document_sources`;
- `content_snapshots`.

Later stages may add a dedicated publication/audit table if operational evidence requires it.

Portable records use `project_key` / source references rather than a foreign key to a YBY-only domain table. Host adapters are responsible for resolving project and brand context.

## 8. Snapshot semantics

Human mode working source of truth before submission is Google Docs.

On Submit the system records:
- provider;
- document_id and URL;
- provider modified time;
- provider revision identifier where available;
- submitted_at / imported_at;
- writer identity;
- normalized structured JSON;
- SHA-256 content hash.

The first imported submission becomes `writer_original` version 1.

Writer Original is immutable. AI Editorial creates a separate `editorial` snapshot.

If the provider document changes after submission, the system reports `changed_after_submission`. It does not silently replace the accepted snapshot. Explicit Re-import creates a new Writer Original version.

## 9. External contracts

Portable Application contracts:

- `ContentSourceAdapter`: converts one accepted external opportunity into Article Base data;
- `BrandContextProvider`: supplies brand/site rules without hardcoding YBY/BYL;
- `InternalLinkProvider`: supplies link candidates and evidence;
- `PublishingAdapter`: preview and publish boundary;
- optional later `MediaAdapter`;
- optional later `AnalyticsCallbackAdapter`.

Google Docs is an infrastructure provider behind a document-import contract introduced in C5, not a concern of Article Base creation.

## 10. YBY adapter boundary

YBY source adapter may read:
- SEO Growth Engine selected opportunities;
- Migration Content Plan;
- manually created Content Opportunities;
- keyword/GSC/Ads evidence;
- business project/site metadata.

It must reuse current SEO-7 dedupe and routing truth. It must not recreate a second SEO selection engine.

Routing rule:
- Product / category canonical opportunity -> Product flow, no Content Article;
- Blog / Guide / Comparison -> Content Article candidate;
- ambiguous commercial editorial -> blocked for explicit routing until a supported type exists.

## 11. Publishing boundary

Content Core never writes directly to arbitrary WordPress admin capabilities.

`PublishingAdapter` must support deterministic preview first. Production publish remains subject to host authorization and Owner gate.

A future Andy Core site-scoped publishing identity may expose only article content, SEO metadata, media references, and authorized publish state.

## 12. Permission boundary

New Content permissions must be independent from Guides permissions.

V1 permission intent:
- `content.view`: open Content OS / own assigned work;
- `content.write`: claim/write/submit writer tasks;
- `content.review`: editorial review actions;
- `content.publish`: layout/publishing actions;
- `content.manage`: configuration / reassignment / exceptional workflow actions.

Writer preset should ultimately receive only the minimal Content permissions required for Writer Pool plus normal dashboard access. Writer must not gain Orders, Finance, Logistics, Customer, or broad Marketing permissions.

Existing Writer preset currently using Guides permissions is legacy behavior and must not be treated as the Content V1 permission model.

## 13. Retry, idempotency, and failure rules

Creation from a source opportunity is idempotent on a stable source identity inside a project/target-site scope.

Writer claim is transaction-safe and single-owner.

External imports and publish operations use explicit idempotency keys or stable provider identifiers where supported.

Retryable external failures leave the workflow state unchanged or enter `blocked` with structured reason; retries must not create duplicate snapshots or duplicate publications.

Invalid state transitions fail closed.

## 14. BYL portability

Core code must not import YBY Traffic models/services.

BYL adoption after C10 supplies:
- BYL Content Source Adapter;
- BYL Brand Context Provider;
- BYL Internal Link Provider;
- BYL Publishing Adapter;
- BYL role/permission wiring.

No YBY/BYL business database is centralized in Jutdo. Jutdo may later provide a read-only owner dashboard only.

## 15. C1 implementation gate

C1 may start when this contract is present on the feature branch.

C1 acceptance:
- Content module skeleton follows repository module conventions;
- portable workflow enums/state machine exist;
- dedicated Content persistence exists;
- module/provider registration passes architecture tests;
- no direct Traffic concrete dependency exists in portable Application code;
- focused tests prove allowed/forbidden workflow transitions and persistence basics.

C2 then implements the first YBY source adapter.