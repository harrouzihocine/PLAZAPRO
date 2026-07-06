---
name: plaza-duplicate-fork-silo
description: Duplicate-client resolution gained a 3rd outcome (fork/separate project) with two-way siloing + the chat/viewers/timeline gate hardening it required
metadata:
  type: project
---

Duplicate-client queue (`/oversight/duplicates`, `clients.duplicates.resolve`) now resolves **three** ways, not two: **Deny**, **Share project** (finder JOINS the existing project — a merge, always reveals client identity), and **Start separate** (`fork_project`).

Fork = a NEW project on the SAME client, `created_by` = the finder, `continued_from_project_id` → the chosen project (oversight-only "Continuation" badge, shown only to `projects.view_all`), `hidden_from_owner = true`. The finder also gets a `ClientDetailGrant` (they must be able to call the client). Design decisions the user made explicitly:
- **Fully silo both directions.** `ClientProject::scopeVisibleTo`/`isVisibleTo` now gate the client-owner branch on `hidden_from_owner = false`, so the client's own agent does NOT see the fork; the finder (not the owner) already can't see the original. Only `view_all` sees both.
- **Reveal the client's identity to the finder** but NOT the original agent's — works for free because `ClientResource` puts `assigned_agent`/`created_by` behind `clients.manage`, not the detail grant.
- The **brief is not copied**: `Desire` is client-level, so the finder inherits it via the client.

**Why the gate hardening was required (non-obvious trap):** a `ClientDetailGrant` makes `collaboratorsVisibleTo` true for EVERY project of that client. Surfaces that gated only on `collaboratorsVisibleTo` (project chat `forProject`, project viewers list) or that listed by `client_id` unscoped (`/clients/{id}/calls`, `/clients/{id}/timeline`) leaked the original agent's work to the finder. Fixed by ALSO requiring project visibility: chat/viewers now need `isVisibleTo && collaboratorsVisibleTo`; calls/timeline now scope logs to `ClientProject::visibleTo` (+ null-project client-level logs). **Rule to remember: a client detail grant unlocks CONTACT, never a project you can't otherwise see.** See [[plaza-project-visibility-collaborators]] and [[plaza-client-workflow-deals]].

Residual: client-level (null `client_project_id`) calls still show to anyone who can see the client — rare in practice (a call attaches to its project on create). Tests: `tests/Feature/Clients/DuplicateClientTest.php` (fork, two-way silo, preview).
