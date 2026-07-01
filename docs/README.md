# PLAZA PRO — Developer Build Playbook

**Real Estate Sales CRM** · Laravel (API, PHP 8.3) · Vue 3 (Vite SPA) · MySQL 8 · Docker · Ubuntu

This `docs/` tree is the **executable build playbook** for PLAZA PRO. It turns the
[Developer Build Guide](reference/PLAZA_PRO_Developer_Build_Guide.docx) into concrete, ordered,
copy‑paste‑runnable steps. Follow it top to bottom for a brand‑new build, or jump to a section when
you need a specific pattern.

> **The golden rule** — *The code follows this structure; this structure does not follow the code.*
> When generated code and these conventions disagree, **the conventions win** — adjust the code, then
> continue. This is what keeps a growing codebase (and AI‑assisted sessions) coherent.

---

## How to use this playbook

1. **Read [`00-overview-and-conventions.md`](00-overview-and-conventions.md) first.** It holds the
   architecture, the five layers, the four foundations, the ten‑step write lifecycle, and the naming
   rules that every later step relies on.
2. **Build Phase 0 completely before any feature.** It is the platform everything else stands on.
   Do not skip ahead — a shaky foundation multiplies cost in every later phase.
3. **Build every feature as a vertical slice.** One thin path from screen to database that actually
   works, following [`conventions/vertical-slice-recipe.md`](conventions/vertical-slice-recipe.md),
   not whole layers in isolation.
4. **Gate each phase.** A phase is done only when you can use the feature on phone and desktop, in both
   themes, with permissions enforced and every action traceable — and its tests pass.

---

## Order of work

| Step | Document | Delivers |
|------|----------|----------|
| — | [`00-overview-and-conventions.md`](00-overview-and-conventions.md) | Architecture, layers, foundations, lifecycle, naming, Definition of Done |
| **Phase 0** | [`phase-0-foundations/`](phase-0-foundations/) | Containers, Laravel+Vue skeletons, auth/RBAC, base model + audit/no‑delete, theming, security baseline, CI |
| Data model | [`database/`](database/) | Full proposed MySQL schema, table by table, per module |
| Phase 1 | [`phase-1-settings.md`](phase-1-settings.md) | Roles, departments, users, ratings, reasons, dynamic lists |
| Phase 2 | [`phase-2-inventory-media.md`](phase-2-inventory-media.md) | Locations, units, boxes, media, stacking plan, 48h reservation hold |
| Phase 3 | [`phase-3-clients-pipeline.md`](phase-3-clients-pipeline.md) | Clients, desire matching, call/visit chain, enforced next action + reminders |
| Phase 4 | [`phase-4-payments-documents.md`](phase-4-payments-documents.md) | Versements, schedules, cancel‑and‑duplicate, branded documents |
| Phase 5 | [`phase-5-collaboration.md`](phase-5-collaboration.md) | Notifications, tasks page, chat (text/image/voice), visibility & sharing |
| Phase 6 | [`phase-6-analytics-audit.md`](phase-6-analytics-audit.md) | Role dashboards, source ROI, unit intelligence, admin audit feed |
| Phase 7 | [`phase-7-hardening-launch.md`](phase-7-hardening-launch.md) | Mobile polish, performance, security pass, backups, deploy |

### Cross‑cutting references

- [`conventions/coding-standards.md`](conventions/coding-standards.md) — PSR‑12/Pint, ESLint/Prettier
- [`conventions/git-workflow.md`](conventions/git-workflow.md) — branching, commits, PR, Definition of Done
- [`conventions/vertical-slice-recipe.md`](conventions/vertical-slice-recipe.md) — the repeatable per‑feature order
- [`conventions/ai-assisted-workflow.md`](conventions/ai-assisted-workflow.md) — how to use AI as an accelerator, not a driver
- [`testing-and-going-live.md`](testing-and-going-live.md) — test strategy + production go‑live checklist
- [`reference/guide-figures.md`](reference/guide-figures.md) — the five diagrams from the guide, transcribed
- [`reference/requirement-traceability.md`](reference/requirement-traceability.md) — guide section → doc coverage matrix

---

## The five commitments this playbook bakes in

1. **Foundations first** — nothing is built on sand. Phase 0 establishes the patterns every later feature reuses.
2. **Extensible by design** — feature modules are isolated; a new one is added without touching the others.
3. **Traceable always** — no‑delete, cancel‑and‑duplicate, and audit logging are part of the base model, not an afterthought.
4. **Responsive & themed** — mobile‑first layouts and a gold/white + gold/black design‑token system from day one.
5. **Quality as you go** — every feature ships as a tested vertical slice, so the app always works.

---

## Status of this deliverable

This pass produced **documentation only** — no application code, migrations, or installers have been
run yet. The proposed database schema in [`database/`](database/) is authored for review; confirm it
before Phase 1 migrations are written. Implementation proceeds phase‑by‑phase, each as its own
reviewed change, following these files.
