# ADR-0035: Standing constraints - a deterministic action gate for "don't build X until Y"

**Status:** Proposed (2026-10-01) - design only, not built. Builds on
[ADR-0002](0002-governance-deferred-guardrails-mandatory.md)'s `trusted`
flag and retired [ADR-0009](resolved/0009-recipe-apply-safety-gate.md)'s apply gate; the
authority rules below depend on [ADR-0033](0033-plausibility-gate-processing-modes.md)
only for untrusted-by-default writes.
**Date:** 2026-10-01

## Context

`aim` memory is meant to inform, and eventually drive, site construction
(Recipes, Tool API plugins per ADR-0013). Some facts are not
descriptive but prohibitive: "don't add the blog content type until the
client approves the editorial plan." If an agent acts on memory, it must
not violate such a fact.

Retrieval cannot be the enforcement mechanism. `recall()` is
probabilistic: a distance cutoff (ADR-0019), phrasing drift ("hold off on
articles") or an unlucky embedding silently drops the constraint, and the
agent builds the blog. A missed recall is invisible. A prohibition has to
be checked deterministically, outside the model's discretion, at the
point where the action happens.

## Decision

Treat a constraint as a distinct kind of fact, enforce it in the action
layer, and keep semantic recall as an advisory second pass only.

1. **Constraint facts are structured.** A constraint is an `aim_fact`
   (category `constraint`, `aim_category` vocabulary) that additionally
   carries:
   - `target`: a machine key naming what is prohibited, e.g.
     `node_type.create:blog`, `recipe.apply:aim_demo_library`, or a Tool
     API plugin ID plus an optional argument match.
   - `release`: how it lifts. One of a date (reuses `expires`), a named
     fact whose `state`/`trusted` flips, or an explicit human release.
     Never "the agent decides".
   - The fact `text` stays, for humans, the review page and fuzzy matching.
2. **The gate lives in the action layer.** Every construction-capable
   tool (Tool API plugins, a recipe apply path) calls one
   service, `AimConstraintChecker::check($action, $args)`, before
   executing. A matching active constraint blocks the call and returns
   the constraint's text and ID as the reason. The check is a plain
   indexed query on `target`, not a vector search. It runs per action,
   not per conversation turn.
3. **Semantic recall is advisory, never blocking.** After the structured
   check passes, a vector pass over constraint facts looks for probable
   conflicts the keys missed (wording drift, unmodeled targets). A hit
   pauses the action and asks a human; it does not refuse outright, so a
   false positive costs one confirmation, not a dead agent.
4. **Only trusted, human-authored constraints bind.** An LLM-extracted
   "don't do X" lands untrusted (ADR-0002) and does not block anything
   until a reviewer with `trust {scope} aim facts` promotes it. Authority
   is by `uid` plus `trusted`, not by the text's claims.
5. **The agent cannot lift a constraint.** Retire/unretire stays on
   `administer aim memory` and is not exposed via `aim_tool` or
   `aim_chatbot`. A human override is a separate, explicit, audited
   operation ("proceed despite constraint N"), logged through
   `AimMemoryManager::logAudit()` with constraint ID, action, uid and
   outcome (no fact text, per the logging rule).
6. **Fail closed on the structured check, open on the advisory one.** If
   the checker itself errors, the action is blocked. If only the
   embedding provider is down, the structured gate still runs and the
   advisory pass is skipped with a warning logged.
7. **Recipe apply is never offered unreviewed.** Carried over from retired
   [ADR-0009](resolved/0009-recipe-apply-safety-gate.md): a recipe-apply
   tool (e.g. `mcp_tools_recipes`' `ApplyRecipe`) is never exposed to an
   LLM-driven caller without a dry-run and human review step in front of
   it. A hard rule, not a grantable permission.

## Consequences

- New fields on `aim_fact` (`target`, `release`) or a small companion
  entity; which one is an open question below. Either way the fields are
  declared by the scope-type seam (ADR-0028) if constraints become a
  scope, or on the base entity if they stay a category.
- Every action-capable tool gains a checker call, so tool authors have a
  rule to follow: no construction tool ships without it. This is the
  real cost; a tool that forgets is an unguarded path.
- Constraints need a review surface: age, target, release condition and
  last-blocked count on the admin listing, so stale constraints
  ("until X" where X happened months ago) are visible and retired by a
  human, not left to rot.
- Two constraints that conflict with each other, or with a newer explicit
  instruction, surface to a human rather than resolving silently.
- Mapping natural language onto `target` keys is the hard part. Extraction
  can propose a `target` for a reviewer to confirm, but a reviewer-confirmed
  key is what binds; a model-guessed key never does by itself.
- Does not make memory a security boundary. A caller with direct Drupal or
  database access bypasses it; this stops a cooperating agent from acting
  against its own recorded instructions.

## Open questions

- Constraint as a `scope`, a category on existing scopes, or a separate
  entity type? A category is cheapest; a separate entity keeps `recall()`
  results free of prohibitions.
- Who may author a binding constraint: `administer aim memory` only, or a
  new `create constraint aim facts` permission per scope?
- Release by "a named fact flips" needs a predicate language. Start with
  date and explicit human release only, and add fact-flip when a real case
  needs it.
- Should the checker also gate non-AI actions (a human using the admin UI
  to add the blog type), or only agent-initiated ones? Gating humans is
  scope creep; start with agent-initiated calls only.
- Whether ADR-0033's plausibility scorer should have any say here. Leaning
  no: a score is a retrieval-quality signal, and a constraint's authority
  should come from a human, not a probability.

## Addendum (2026-10-01): ECA as a second call site, FlowDrop for release flows

Neither owns the constraint data. Layering:

1. **Memory** holds constraints and `AimConstraintChecker` (the
   decision above). Unchanged.
2. **ECA is an enforcement adapter, not the enforcer.** A small ECA
   condition plugin ("does an active constraint match this target?")
   would call `AimConstraintChecker`, and ECA models would wire it to
   `eca.content_entity.presave` and access events. This catches human
   admin-UI actions as well as agent calls, which answers the open
   question about gating humans without per-tool checker calls.
   Caveats: ECA blocks only by veto-style actions (`SetValidationError`,
   `SetAccessResult`, an exception); whether config-entity creation (a
   content type) exposes a blockable event is **unverified** and must be
   checked first. The aim ECA bridge was removed, so the condition plugin
   is new work. ECA models are config and can be edited or disabled, so
   per-tool checker calls stay the baseline and ECA is defense in depth.
3. **FlowDrop (2.6.0 stable, Security Team covered) is the release and
   approval layer.** Its human-in-the-loop nodes pause for a named
   reviewer and record who approved what and when; a flow that pauses and
   then flips a constraint's release state replaces a release-predicate
   language and supplies the audit trail. The same shape can serve as
   the recipe-apply dry-run/review gate (generate recipe, validate, human
   approval, apply). It is not an enforcer: it controls flows it runs,
   and whether its entity triggers can veto (versus react after the fact)
   is unverified.

Based on documentation only; neither adapter is installed or spiked.
Spike FlowDrop in a scratch site first (as with `ai_decision`), then
verify the config-entity event question for ECA.
