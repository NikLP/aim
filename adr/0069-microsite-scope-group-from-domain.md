# ADR-0069: A microsite scope from the domain's group, in an optional module

**Status:** Proposed 2026-11-22. Nothing built, nothing tested against a
LocalGov Microsites site. Builds on
[ADR-0063](resolved/0063-scopes-as-config-on-core-plugins.md)'s scope plugins
and follows [ADR-0068](0068-case-entity-module.md)'s pattern of an optional
module for a scope that needs more than core. The literals half is aim's
requirement only; its design belongs in literals'
[DESIGN.md](../../../contrib/literals/DESIGN.md) (ADR-0040).
**Date:** 2026-11-22

## In plain terms

Before: on a site serving several microsites from one Drupal install, every
fact is either for the whole site or for a person, role, case or entity. A
fact true for one microsite ("the West Vale library closes at 5pm") would be
recalled on all of them.

After: an optional module adds a `microsite` scope whose subject is a group.
On a microsite's domain, recall returns that group's facts and no other
microsite's.

Gains: one site can hold many microsites' memories without them mixing, which
is how LocalGov Microsites is built. Costs: a dependency on Group and Group
Context: Domain (kept out of core `aim`), a second permission system (Group's)
to reconcile with aim's trust rule, and exact values (literals) still need
their own answer, below.

## Context

LocalGov Microsites (`localgov_microsites_group`) runs each microsite as a
Group, served on its own domain. It used `domain_group` and has since migrated
to `group_context_domain` (its install file migrates the domains; checked
2026-11-22). `group_context_domain` (1.0.0, Drupal 9.5 to 11) stores the
group's UUID as a third-party setting on the `domain` config entity, provides
a group context from the active domain (`GroupFromDomainContext`) and a cache
context `url.site.group`. `domain_group` itself is not a target: 30 installs,
no Drupal 11 release.

[ADR-0066](0066-one-instance-one-recipe.md) says scopes separate who may see a
fact, not which body of knowledge it belongs to, and puts separate bodies of
knowledge on separate sites. LocalGov has already chosen one site for many
microsites, so that answer is not open here: the partition has to live inside
the site. This scope is the one place a scope does mark a body of knowledge.

Recall checks each result with `$entity->access('view')`
(`AimMemoryManager::executeSearchQuery()`), so a scope plugin's
`checkViewAccess()` is already where a per-request rule can apply. The plugin's
result is ORed with the flat `view {scope} aim facts` permission.

## Decision

1. **An optional module, working name `aim_group_domain`**, depending on
   `group` and `group_context_domain`. It ships a `group` scope-type plugin
   and a `microsite` scope (config/optional) using it. Core `aim` gains no
   Group code. (`aim_group` is not reused as a name: it was ADR-0030's retired
   fact-groups module.)
2. **The subject is the group ID**, in `subject` like the role and case
   scopes. `validateSubject()` refuses a group that does not exist.
3. **`checkViewAccess()` allows a fact whose group is the current domain's
   group**, read from `GroupFromDomainContext`, and adds `url.site.group` to
   the access result's cache metadata. Visitors on a microsite get no flat
   `view microsite aim facts` permission; that permission is the staff
   overview across all microsites. An MCP client connecting through a
   microsite's domain gets that microsite.
4. **Literals: aim needs per-microsite exact values without changing
   literals' key model.** The key is the literal's entity ID (unique), so
   `[literal:main_phone]` cannot hold two values today. aim asks literals for
   two things, both buildable as a literals submodule:
   - **A group field and access**, for a value only one microsite has: a base
     field on `literal` and a `hook_literal_access()` that hides it on other
     domains. `LiteralReader::load()` already checks view access and collects
     its cache metadata, so tokens, `literals.lookup` and the finder all
     follow.
   - **A per-group resolver**, for a key every microsite shares: one literal
     (one key, one gist) whose resolver reads the value from the current
     group, for example from a field on the group entity, with
     `url.site.group` on its cache metadata.
   This does not reopen ADR-0061's rejected "literal scope": literals still
   has no aim scope, mirror or second vector row.

## Consequences

- Recall still post-filters by access. A microsite with few facts among many
  can come back with fewer results than asked for. Pre-filtering by subject
  needs ADR-0059's `extra_filters` seam (proposed, not built).
- Granting `view microsite aim facts` to a visitor role would show every
  microsite's facts, because the plugin's rule is ORed with it. The module's
  README must say so.
- On the main site's own domain (no group in context) no microsite fact is
  recalled.

## Open questions

- **Writing and trust per group.** `create microsite aim facts` and `trust
  microsite aim facts` are site-wide, so an editor of one microsite could
  write into another, or trust another's facts. Either the plugin checks a
  Group permission on write (which `validateSubject()` cannot see without the
  writer), or aim's trust rule (ADR-0065) learns about group roles. Undecided.
- **Whether site-scope facts apply on a microsite.** A council's opening hours
  may be wrong on a microsite. Possibly a plugin setting that hides `site` facts
  on group domains.
- **The per-group resolver's fallback** on a domain with no group, or a group
  with the field empty: no value, or the literal's own stored value.
- Whether LocalGov Microsites groups carry fields (contact details) that a
  resolver can read directly, or each site adds them.
