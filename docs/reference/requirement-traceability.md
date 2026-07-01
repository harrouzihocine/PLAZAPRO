# Reference — Requirement Traceability Matrix

Every section, table and figure of the [Developer Build Guide](PLAZA_PRO_Developer_Build_Guide.docx)
mapped to the playbook document that covers it. Target: **100% coverage** — a row with no target is a
gap. Verified: no gaps.

---

## Guide sections → docs

| Guide § | Topic | Covered by |
|---------|-------|-----------|
| 1 · How to Use / What You Will Build | intro, five commitments | [`../README.md`](../README.md), [`../00-overview-and-conventions.md`](../00-overview-and-conventions.md) §1 |
| 2 · Architecture at a Glance | five layers, four foundations | [`../00-overview-and-conventions.md`](../00-overview-and-conventions.md) §2 |
| 2.1 · The Layers | presentation→infrastructure | [`../00-overview-and-conventions.md`](../00-overview-and-conventions.md) §2 |
| 2.2 · Shared Foundations (Table 2.1) | auth, audit, dynamic settings, media | overview §2; built in Phase 0 steps 04–10 |
| 3 · Containerized Dev Environment | Docker on Ubuntu | [`../phase-0-foundations/00-prerequisites-ubuntu.md`](../phase-0-foundations/00-prerequisites-ubuntu.md) |
| 3.1 · Install Tools on Ubuntu | Docker/Compose/Git | [`../phase-0-foundations/00-prerequisites-ubuntu.md`](../phase-0-foundations/00-prerequisites-ubuntu.md) |
| 3.2 · Project Layout on Disk | mono‑repo | [`../phase-0-foundations/01-repo-and-layout.md`](../phase-0-foundations/01-repo-and-layout.md) |
| 3.3 · The Compose File | docker‑compose.yml | [`../phase-0-foundations/02-docker-stack.md`](../phase-0-foundations/02-docker-stack.md) |
| 3.4 · Daily Commands | up/down/exec, aliases | [`../phase-0-foundations/02-docker-stack.md`](../phase-0-foundations/02-docker-stack.md) |
| 4 · Backend Foundation — Laravel | REST API, modules | [`../phase-0-foundations/03-laravel-install.md`](../phase-0-foundations/03-laravel-install.md), [`07-modules-skeleton.md`](../phase-0-foundations/07-modules-skeleton.md) |
| 4.1 · Folder Structure | app/Modules | [`../phase-0-foundations/07-modules-skeleton.md`](../phase-0-foundations/07-modules-skeleton.md) |
| 4.2 · Base Model | BaseModel + traits, no‑delete | [`../phase-0-foundations/04-core-base-model.md`](../phase-0-foundations/04-core-base-model.md) |
| 4.3 · Activity Log (Table 4.1) | append‑only audit | [`../phase-0-foundations/05-activity-log.md`](../phase-0-foundations/05-activity-log.md) |
| 4.4 · RBAC — permissions on every route | one role, `is_agent` | [`../phase-0-foundations/06-auth-and-rbac.md`](../phase-0-foundations/06-auth-and-rbac.md) |
| 4.5 · Request Lifecycle | 10 steps | [`../00-overview-and-conventions.md`](../00-overview-and-conventions.md) §3, [`guide-figures.md`](guide-figures.md) Fig 4.1 |
| 4.6 · Naming Conventions | models/actions/… | [`../00-overview-and-conventions.md`](../00-overview-and-conventions.md) §4, [`../conventions/coding-standards.md`](../conventions/coding-standards.md) |
| 5 · Frontend Foundation — Vue 3 | SPA | [`../phase-0-foundations/08-vue-install.md`](../phase-0-foundations/08-vue-install.md) |
| 5.1 · The Stack (Table 5.1) | Vue/Vite/Router/Pinia/Axios/Tailwind | [`../phase-0-foundations/08-vue-install.md`](../phase-0-foundations/08-vue-install.md) |
| 5.2 · Folder Structure | feature‑based | [`../phase-0-foundations/08-vue-install.md`](../phase-0-foundations/08-vue-install.md) |
| 5.3 · Theming | gold/white + gold/black tokens | [`../phase-0-foundations/09-theming-and-appshell.md`](../phase-0-foundations/09-theming-and-appshell.md) |
| 5.4 · Mobile‑First & Responsive | AppShell, bottom nav | [`../phase-0-foundations/09-theming-and-appshell.md`](../phase-0-foundations/09-theming-and-appshell.md) |
| 5.5 · The API Layer | useApi wrapper | [`../phase-0-foundations/08-vue-install.md`](../phase-0-foundations/08-vue-install.md) |
| 6 · Database Foundation — MySQL | migrations, conventions | [`../database/00-schema-overview.md`](../database/00-schema-overview.md) |
| 6.1 · Conventions | plural snake_case, status, indexes | [`../database/00-schema-overview.md`](../database/00-schema-overview.md) §1 |
| 6.2 · No‑Delete Pattern in Schema | `supersedes_id` migration | [`../database/00-schema-overview.md`](../database/00-schema-overview.md) §2, [`../database/04-payments.md`](../database/04-payments.md) |
| 6.3 · Core Tables (re‑use the ERD) | entities & relationships | [`../database/`](../database/) 00–06 (**proposed schema — ERD not in project**) |
| 7 · Coding Standards & Git Workflow | Pint/ESLint, branching, DoD | [`../conventions/coding-standards.md`](../conventions/coding-standards.md), [`../conventions/git-workflow.md`](../conventions/git-workflow.md) |
| 7.1 · Automated Style | Pint, ESLint, Prettier | [`../conventions/coding-standards.md`](../conventions/coding-standards.md) |
| 7.2 · Git Branching | feature/fix branches, commits | [`../conventions/git-workflow.md`](../conventions/git-workflow.md) |
| 7.3 · Definition of Done | merge gate | [`../conventions/git-workflow.md`](../conventions/git-workflow.md), overview §6 |
| 8 · Building with AI Assistance | rules + control | [`../conventions/ai-assisted-workflow.md`](../conventions/ai-assisted-workflow.md) |
| 8.1 · Give It the Rules | feed guide, one slice | [`../conventions/ai-assisted-workflow.md`](../conventions/ai-assisted-workflow.md) |
| 8.2 · Stay in Control | review, run, small slices, golden rule | [`../conventions/ai-assisted-workflow.md`](../conventions/ai-assisted-workflow.md) |
| 9 · The Build Sequence | phased, vertical slice | [`../conventions/vertical-slice-recipe.md`](../conventions/vertical-slice-recipe.md), phase docs |
| 9.1 · Phase 0 — Foundations | do first | [`../phase-0-foundations/`](../phase-0-foundations/) 00–12 |
| 9.2 · Phases 1–7 (Table 9.1) | feature by feature | [`../phase-1-settings.md`](../phase-1-settings.md) … [`../phase-7-hardening-launch.md`](../phase-7-hardening-launch.md) |
| 10 · Testing, Quality & Going Live | tests, deploy | [`../testing-and-going-live.md`](../testing-and-going-live.md) |
| 10.1 · Testing | Pest/Vitest, key rules, CI | [`../testing-and-going-live.md`](../testing-and-going-live.md) §1 |
| 10.2 · Going Live | prod containers, backups | [`../phase-7-hardening-launch.md`](../phase-7-hardening-launch.md), testing §2 |
| 10.3 · Foundation Checklist | the gate | [`../phase-0-foundations/12-phase-0-checklist.md`](../phase-0-foundations/12-phase-0-checklist.md) |

## Tables → docs

| Table | Covered by |
|-------|-----------|
| 2.1 · Four shared foundations | overview §2; Phase 0 steps 04–10 |
| 4.1 · `activity_log` columns | [`../phase-0-foundations/05-activity-log.md`](../phase-0-foundations/05-activity-log.md) |
| 5.1 · Frontend stack | [`../phase-0-foundations/08-vue-install.md`](../phase-0-foundations/08-vue-install.md) |
| 9.1 · Phases 1–7 | phase docs 1–7 |

## Figures → docs

| Figure | Covered by |
|--------|-----------|
| 2.1 · Architecture | [`guide-figures.md`](guide-figures.md), overview §2 |
| 3.1 · Container topology | [`guide-figures.md`](guide-figures.md), [`../phase-0-foundations/02-docker-stack.md`](../phase-0-foundations/02-docker-stack.md) |
| 4.1 · Request lifecycle | [`guide-figures.md`](guide-figures.md), overview §3 |
| 5.1 · Two themes | [`guide-figures.md`](guide-figures.md), [`../phase-0-foundations/09-theming-and-appshell.md`](../phase-0-foundations/09-theming-and-appshell.md) |
| 9.1 · Build sequence | [`guide-figures.md`](guide-figures.md), phase docs |

---

## Product rules explicitly captured (guide‑named, all covered)

| Rule | Doc |
|------|-----|
| One role per user | [`../database/01-settings.md`](../database/01-settings.md), [`../phase-0-foundations/06-auth-and-rbac.md`](../phase-0-foundations/06-auth-and-rbac.md) |
| `is_agent` flag drives visit assignment | [`../phase-0-foundations/06-auth-and-rbac.md`](../phase-0-foundations/06-auth-and-rbac.md), [`../database/03-clients-pipeline.md`](../database/03-clients-pipeline.md) |
| No hard delete / cancel‑and‑duplicate / versioning | [`../phase-0-foundations/04-core-base-model.md`](../phase-0-foundations/04-core-base-model.md) |
| Append‑only activity log | [`../phase-0-foundations/05-activity-log.md`](../phase-0-foundations/05-activity-log.md) |
| Dynamic lists back every dropdown | [`../database/01-settings.md`](../database/01-settings.md), [`../phase-1-settings.md`](../phase-1-settings.md) |
| Project‑ & unit‑level media, versioned | [`../database/02-inventory.md`](../database/02-inventory.md), [`../phase-2-inventory-media.md`](../phase-2-inventory-media.md) |
| Visual stacking plan | [`../phase-2-inventory-media.md`](../phase-2-inventory-media.md) |
| 48‑hour reservation hold | [`../phase-2-inventory-media.md`](../phase-2-inventory-media.md), [`../database/02-inventory.md`](../database/02-inventory.md) |
| Desire → inventory matching | [`../phase-3-clients-pipeline.md`](../phase-3-clients-pipeline.md) |
| Call → office‑visit → apartment‑visit chain | [`../phase-3-clients-pipeline.md`](../phase-3-clients-pipeline.md) |
| Enforced next action + auto‑reminders | [`../phase-3-clients-pipeline.md`](../phase-3-clients-pipeline.md), [`../database/03-clients-pipeline.md`](../database/03-clients-pipeline.md) |
| Versements + schedules + cancel‑and‑duplicate | [`../phase-4-payments-documents.md`](../phase-4-payments-documents.md), [`../database/04-payments.md`](../database/04-payments.md) |
| Branded document generation | [`../phase-4-payments-documents.md`](../phase-4-payments-documents.md) |
| Archive‑only‑without‑payments | [`../phase-4-payments-documents.md`](../phase-4-payments-documents.md) |
| Chat (text/image/voice) + visibility/sharing | [`../phase-5-collaboration.md`](../phase-5-collaboration.md), [`../database/05-collaboration.md`](../database/05-collaboration.md) |
| Notifications + tasks page | [`../phase-5-collaboration.md`](../phase-5-collaboration.md) |
| Role dashboards, source ROI, unit intelligence, audit feed | [`../phase-6-analytics-audit.md`](../phase-6-analytics-audit.md), [`../database/06-analytics-audit.md`](../database/06-analytics-audit.md) |
| Mobile‑first / responsive / both themes | [`../phase-0-foundations/09-theming-and-appshell.md`](../phase-0-foundations/09-theming-and-appshell.md) |
| CI on every push | [`../phase-0-foundations/11-ci-pipeline.md`](../phase-0-foundations/11-ci-pipeline.md) |

---

## Open items to confirm with the client (not gaps in the guide)

1. **The Scope of Work / ERD** is not in the project — the schema in [`../database/`](../database/) is
   **proposed**. Confirm entities/fields before Phase 1 migrations.
2. **`client_projects` vs. flat `clients`** — the deal layer is included (guide's
   `versements.client_project_id`); confirm whether a leaner v1 collapses it.
3. **Starting values** for seeded dynamic lists (payment methods, sources, ratings, reasons, unit/box types).
4. **Currency & rounding** convention for versements.
5. **Real‑time transport** for chat/notifications (polling first; Echo/Reverb optional).
