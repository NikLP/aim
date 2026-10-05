# ADR-0039: `scope: token` - facts whose value is a live config page field

**Status:** Superseded 2026-10-05 by [ADR-0040](0040-literals-probabilistic-lookup-of-exact-values.md) - design only, never built.
**Date:** 2026-10-03

**Superseded.** The `token` scope is a pointer-only fact (a label plus a
token), which ADR-0040 Addendum 5 rejected: it has no searchable text of its
own, and the finder can be called directly. There is no `token` scope, no
`token` base field on `aim_fact`, and no `renderText()` or
`isConsolidatable()` scope seams. What carried over into ADR-0040: a token
as a value kind (v2, with `config_pages` as the optional dependency), the
view-access check at resolve (the `config_pages` token handler has none),
the human-gated "add setting" and propose-candidates-from-facts flows (now
convert-a-fact, piece 12), and a literal being verbatim by nature so never
consolidated. The text below is kept as the record of the original design.

## Context

A visitor asks the chat bot "what is the phone number?". The answer may
live in a fact or in a site setting, and the bot has no way to choose:
`ai_agents` ships config-reading function calls (`list_config_entities`,
`ai_agent_get_config_schema`), but they return raw config-entity data for
site-building agents, and no `tool`/`eca`/`mcp_server` plugin reads a
single setting by key. Today the model can only call `aim_recall`.

Storing the value as plain fact text works but drifts: someone edits the
setting and the fact goes stale.

`drupal/config_pages` (4.0.1, in `composer.json`) gives editors a form per
page type, per-type `view`/`edit {type} config page entity` permissions,
and a `[config_page:{type}:{field}]` token for any type with its `token`
flag set. Checked directly: the token handler
(`ConfigPagesTokensHooks::tokens()`) loads the page and generates the
value with **no view-access check**, so any caller of `Token::replace()`
gets the value.

## Decision

Add a `token` scope: a fact whose text is a human-readable **label**
("Phone number") and whose value is resolved **live, at recall time**,
from a token held in a separate field.

- **Shipped as an `aim_scope_token` submodule**, ADR-0026/ADR-0028 shape:
  one `aim_scope` instance (`plugin: token`), one `AimScopeToken`
  `AimScopeTypeInterface` plugin, no other scope touched.
- **A `token` base field** (string, max 255, e.g.
  `[config_page:contact:phone]`), declared by
  `AimScopeToken::getBaseFieldDefinitions()` with
  `->setProvider('aim_scope_token')` (ADR-0028's easy-to-miss
  requirement). Validated on write: exactly one token, type
  `config_page`, naming an existing page type and field.
- **The vector index sees only the label.** `text` is what gets embedded,
  so a config edit never touches the index and there is nothing to
  re-index. The token and its resolved value never enter the index.
- **Resolution in `recall()`, never stored.** A new interface method,
  `renderText(AimFact $fact, AccountInterface $account): ?string`, turns
  a fact into the text `recall()` returns. Default: the stored `text`.
  `AimScopeToken` returns `"{label}: {resolved value}"`. `recall()` calls
  it generically through the plugin manager, so no scope ID is hardcoded
  in `AimMemoryManager`, and every consumer (`aim_chatbot`, `aim_tool`
  over MCP, Drush) gets it from the one place.
- **Access delegates to the config page, the `aim_scope_entity` pattern
  ([ADR-0027](resolved/0027-entity-scope.md)).**
  `AimScopeToken::checkViewAccess()` loads the page named by the token and
  returns `$page->access('view', $account, TRUE)`, inheriting its
  cacheability. The scope never grants the flat `view token aim facts`
  permission, so the config page's own permission is the **single** gate.
  This closes the gap above without patching `config_pages`. Restricted
  values get their own page type per audience (a public/staff split, not
  a type per role).
- **Unresolved token drops the fact from the result.** Covers a missing
  page, a missing field, an empty value, and access denied. An audit
  line records fact ID, scope and reason, never the value
  (CLAUDE.md logging rules). A scope setting `debug_render_token`
  (default off) returns the raw token string instead, **only** to callers
  holding `administer aim memory`; to anyone else it leaks config
  structure.
- **A token fact is never consolidated.** New interface method
  `isConsolidatable(): bool`, TRUE by default, FALSE on `AimScopeToken`.
  A PHP interface cannot carry a default, so this adds
  `AimScopeTypeBase` (extends `PluginBase`, implements the interface,
  supplies the defaults for `isConsolidatable()` and `renderText()`); the
  three existing plugins extend it, `AimScopeToken` overrides.
  Consolidation checks it in the same three places
  [ADR-0020](0020-verbatim-facts-consolidation-opt-out.md) names:
  `AimHooks::factInsert()` (no enqueue), `consolidate()`'s sweep query
  (excluded as a candidate), and `findNearestNeighbor()` (skipped as a
  neighbor, beside the existing `expires` skip). A token fact is then
  never `kept` or `candidate`.
- **Guardrails** run on the label as for any fact. The resolved value is
  admin-entered on the config page and is not Guardrail-checked.

## Building the config pages from facts (separate, human-gated)

Not part of the scope itself. A later Drush command (or admin report)
proposes **candidates** from existing facts (label, suggested page type
and field, current value). A human approves the list; a deterministic
step then creates the `config_pages_type`, field storage and field
config, and converts the fact (original text kept in provenance, label and
token set, original value not deleted until the config page value is
confirmed). The LLM only proposes; it never writes schema, consistent
with [ADR-0035](0035-standing-constraints-action-gate.md).

Two ways in, both feeding the same approval step:

- **Periodic scan (nice to have, later).** The candidate proposal can
  also run on a schedule through the Queue API
  ([ADR-0003](resolved/0003-async-processing-dedicated-crontab.md)'s
  dedicated crontab, never `hook_cron`), so new setting-like facts
  surface in the review list without anyone asking.
- **Manual "add setting" (preferred first).** The console form from
  [ADR-0029](0029-context-carrying-turns.md) gets an "add setting"
  action: pick or create the config page type and field, enter the
  label and value, and it writes the config page value and the token
  fact together. No LLM involved, so it needs no approval step beyond
  the editor's own click. Build this before the scan; it may make the
  scan unnecessary on a small site.

## Alternatives rejected

- **Token string in the fact text.** Embedding the raw token ruins the
  match for the question; embedding the resolved text means re-indexing on
  every config save, with a token-to-facts reverse lookup. Guardrails,
  consolidation and extraction would also see the raw token.
- **A `value_token` field on any scope's fact.** Keeps `role`/`user`
  visibility, but leaves two permission systems (the fact's scope and the
  config page's) free to disagree, and needs a per-fact consolidation
  flag. ADR-0020's `verbatim` field remains the right answer for
  per-fact opt-out on other scopes; this scope is verbatim by nature.
- **Patching `config_pages` to check view access in its token handler.**
  A contrib patch to carry; unnecessary once our plugin checks access.
- **A generic "read setting by key" tool.** No access model, and nothing
  to unify with fact discovery; the model would still have to choose.
- **ECA.** The gap is a missing read path, not orchestration.
- **Overriding `system.site` reads to forward to config pages.** Fragile;
  facts can use core tokens (`[site:mail]`) directly if that is wanted.

## Consequences

- One shared resolver: `renderText()` is a general seam, usable by a
  future scope that stores a pointer rather than text.
- New base field `token` on `aim_fact` (provider `aim_scope_token`): no
  `hook_update_N()` while there is no real data (PoC); needed once there is.
- `config_pages` becomes a dependency of `aim_scope_token` only, not of
  core `aim`.
- Restricting a value means a separate page type, so many audiences mean
  many types. Accepted; keep to two or three.
- A token fact is invisible to recall unless the config page is
  viewable, so a misconfigured permission fails silent (the fact just
  disappears). The audit line is how to notice it.

## Open questions

- Only `config_page` tokens in the first version. Core tokens such as
  `[site:mail]` have no access object to delegate to; neutral access
  would fall back to the flat permission. Decide whether to allow them.
- Whether `ai_chatbot`'s response path should also show the label when a
  token drops out ("phone number: not set"), rather than saying nothing.
- Whether `renderText()` should also be applied to the admin fact list
  (the label alone is what an editor sees today).
- Whether "add setting" can create a new config page type and field
  inline, or only fill existing ones (creating schema from a form is the
  same deterministic step as the candidate flow, just human-initiated).
