# ADR-0048: Token literals - resolve for the asking account, and name an entity target for entity data

**Status:** Part A built 2026-10-05; Part B proposed, unbuilt.
**Date:** 2026-10-05
Relates to [ADR-0040](0040-literals-probabilistic-lookup-of-exact-values.md)
(Addendum 7: types, kinds, audience).

## Context

The `token` literal kind stored a string such as `[site:name]` and replaced
it at read time. Two problems:

1. `resolve($literal, $account)` ignored `$account`: `Token::replace()` ran
   with no data, so `[current-user:*]` (which core loads from
   `\Drupal::currentUser()`, not from token data) resolved for the session
   user. An agent, queue worker or Drush call acting for account B inside
   session A would get A's data cached under B's context. Resolution was not
   a pure function of its arguments.
2. Access to the data a token reveals was never checked. It did not bite
   only because no entity data was passed, so `[node:*]` and `[user:*]`
   resolved empty. The `entity` and `url` kinds check view access on their
   target; `token` did not. The audience column gates the *viewer against the
   literal*, not the *viewer against the data*, so an author with
   `create literals` could publish whatever a token reveals to any audience.

`AccountSwitcherInterface::switchTo()` would have fixed 1 by making the
session lie. Rejected: it hides the dependency instead of removing it, and
leaves 2 untouched.

## Decision

### Part A (built): resolution is a function of `($literal, $account)`

- `[user:...]` always means the asking account: `resolve()` passes
  `['user' => User::load($account->id())]` and adds the `user` cache context.
- `validate()` rejects `current-user` (session-bound) and any token type
  whose token info sets `needs-data`, other than `user` (the kind cannot
  supply the entity). The test is the token system's own flag, not a
  hardcoded list.
- Net effect: a token literal can only reveal global values (`site`,
  `current-date`) and the asking account's own data. Nothing crosses an
  account boundary, by construction rather than by author trust.

### Part B (proposed): entity data via an explicit target

Entity tokens (`[node:title]`, `[user:mail]` of another user) are core to the
module's purpose, so they must be supported, with the access check the
`entity` kind already does:

- The literal names its target as `entity_type:id`, same format as the
  `entity` kind.
- `resolve()` loads the target and requires `access('view', $account)`. On
  denial or a missing target it returns `NULL` (never a partial string).
- The loaded entity is passed as token data under its type
  (`['node' => $node]`); the entity's cache tags and the access result's
  cacheability are merged into the caller's metadata.
- `validate()` accepts a type with `needs-data` only when it matches the
  target's entity type.

**Open: where the target lives.** Options:

| Option | For | Against |
| --- | --- | --- |
| Second base field `target` on `literal`, used by the token kind only | Value stays a plain template; validated and queried as data; fits how `entity` stores `type:id` | A column unused by three of four kinds; an entity-wide change needing an update hook |
| Composite value `node:12\|[node:title]` | No schema change | Parsing inside one string; both parts validated by hand; ambiguous if a template contains `\|` |
| A per-type setting fixing the target (type "Library node") | No per-literal field; access decision made once per type | One target per type; wrong for many literals |

Recommendation: the second field, shown only when the type's kind is
`token`. It keeps the access-checked reference a first-class value, which is
what the `entity` kind already does.

## Consequences

- Part A needs no migration; the one existing token literal (`[site:name]`)
  is unaffected. `current-user` literals saved earlier would now fail
  validation on next save and resolve without that token's data.
- Callers must still pass and bubble `CacheableMetadata`; nothing calls
  `Literal::resolve()` yet (HANDOFF Phase 2 item 1). Without bubbling, per
  user resolved text can be cached for the wrong user.
- Part B adds a view-access check per resolve; entity-bearing results get
  the entity's tags, so editing the node invalidates cached literals.

## Open questions

- Part B target storage (table above).
- Whether `user` data for another account (`[user:mail]` of user 7) belongs
  in Part B's target or stays banned. Default: it is just an entity target,
  with the user's own view access deciding.
