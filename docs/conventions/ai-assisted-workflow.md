# Conventions — Building Efficiently with AI Assistance

You will use an AI assistant to write much of the code. Used well, it dramatically speeds up delivery;
used carelessly, it produces inconsistent code that erodes the foundations. These habits keep it firmly
an **accelerator** (guide §8).

---

## Give it the rules

- **Feed it this playbook.** Point the assistant at these conventions (structure, base model, naming,
  the ten‑step lifecycle) at the **start of a session**, so it generates code that fits. Start with
  [`../00-overview-and-conventions.md`](../00-overview-and-conventions.md) and the relevant
  [`../database/`](../database/) doc.
- **Work one vertical slice at a time.** Ask for a single feature end to end — migration, model, action,
  controller, request, resource, store, Vue view — **not a whole layer at once**
  ([`vertical-slice-recipe.md`](vertical-slice-recipe.md)).
- **Show an example.** Once one module follows the pattern, ask the assistant to **mirror** it for the
  next. Consistency compounds.

## Stay in control

- **Review every change.** Read what it produced before accepting; confirm it uses an **Action** for
  logic, **checks a permission**, and **never deletes**.
- **Run it.** Let the tests and the app — not the chat — be the proof that something works.
- **Keep slices small.** Small, tested increments are easy to verify and easy to roll back; large dumps
  of code are neither.

## The golden rule

> **The AI follows your structure; your structure does not follow the AI.** When generated code and
> these conventions disagree, **the conventions win** — adjust the code, then continue.

## A good per‑slice prompt (template)

```
Build the "<feature>" vertical slice for the <Module> module, following docs/conventions/vertical-slice-recipe.md.

Context:
- Schema: docs/database/<file>.md (table <name> already designed).
- Base model + traits: docs/phase-0-foundations/04-core-base-model.md (extend BaseModel; no hard delete).
- Permission slug: <resource.action>.
- Key rule to enforce and test: <the rule, e.g. 48h expiry / next-action required>.

Produce, in order: migration, model, Action, FormRequest, Controller, API Resource, route (guarded by
auth:sanctum + can:<perm>), Pinia store + api.js, Vue view (responsive, tokens only), and a Pest feature
test for the happy path AND the key rule. Match existing naming conventions exactly.
```

## Review checklist before accepting AI output

- [ ] Business logic is in an **Action**, not the controller/model.
- [ ] Route is guarded by `auth:sanctum` **and** a `can:<permission>`.
- [ ] Validation is in a **FormRequest**; output via an **API Resource**.
- [ ] Model extends `BaseModel`; no `delete()` path; `$fillable` allow‑list.
- [ ] No hard‑coded colours (tokens only); responsive/mobile‑first.
- [ ] A test covers the **key rule**, and it passes.
- [ ] Pint/ESLint clean.
