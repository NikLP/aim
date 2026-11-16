# ADR-0068: Cases as entities, in an optional `aim_case` module

**Status:** Proposed 2026-11-16. Nothing built. Keeps
[ADR-0063](resolved/0063-scopes-as-config-on-core-plugins.md)'s case
scope and `case` plugin in core `aim` unchanged; builds on
[ADR-0028](resolved/0028-scope-type-plugin.md)'s plugin type and
[ADR-0057](0057-dates-resolved-and-confirmed-before-write.md)'s expiry.
**Date:** 2026-11-16

## In plain terms

Before: a case is only a string in a fact's `subject` (`case-site-redesign`).
The scope's description calls a case "ongoing work with a start and an
end", but nothing stores the end, so nothing happens when the work is
over: its facts stay in recall until someone retires them one by one. A
mistyped case ID silently starts a new case, and no list of cases exists
to pick from.

After: core `aim` works as now, with plain-string cases and no setup.
A site that wants more turns on `aim_case`. Each case is then a small
entity with a name, an open or closed status and an optional end date.
New facts must name an open case. When a case closes, by hand or because
its end date has passed, its facts leave recall. They come back if the
case reopens.

Gains: cases end; typos stop starting new cases; a place for the later
pieces (picker, members, access) that need a case to be a thing. Costs:
a new module; two case modes in the code, string-only and entity-backed;
one small seam in core's expiry sweep.

## Context

What cases lack today, all parked separately, all needing a case to be
more than a string:

| Item | Where | Needs |
| --- | --- | --- |
| A case end date and closing a case | raised 2026-11-16 | somewhere to store the end |
| Case subject constraint (typo starts a new case) | TODO.md "Case scope" | a list of cases that exist |
| Case picker widget | BACKLOG.md | the same list, plus view access |
| Case access control and membership | BACKLOG.md, ADR-0029 | members per case |
| One subject field pair | BACKLOG.md | triggered by "cases become a real entity" |

A fact can already carry its own end (`expires`, ADR-0057), and that
stays. A case's end is a different thing: "the redesign uses Olivero" has
no date of its own but stops mattering when the redesign is done. Copying
the case end into each fact's `expires` was considered and rejected:
once copied, a fact's own end and an inherited one can't be told apart,
so extending a case's end date can't extend its facts.

Why a module and not core: not every site wants cases as records with a
lifecycle (`aim_personal` likely does not), and plain-string cases
already work for those that only want facts grouped. Why not the
ADR-0026 shape (a scope submodule with its own plugin ID): a scope's
config naming a plugin only a module provides is what broke on uninstall
then (`PluginExists`, `ScopeUninstallValidator`). Here the scope config
keeps `plugin: case`, and the module changes only the class behind it.

## Decision

1. **`aim_case` is an optional submodule** (`modules/aim_case/`) that
   depends on `aim`. Core keeps the case scope config and `AimScopeCase`
   as they are; a site without `aim_case` sees no change.
2. **The module swaps the plugin class, not the plugin ID.**
   `hook_aim_scope_type_info_alter()` (the manager already calls
   `alterInfo('aim_scope_type_info')`) points the `case` plugin at
   `AimScopeCaseEntity extends AimScopeCase`. The scope config never
   names anything the module provides, so installing or uninstalling it
   leaves config valid.
3. **`aim_case` content entity.** `id` (the case ID string, the same
   value facts hold in `subject`, so no fact changes and the subject
   fields stay as they are), `label`, `status` (open/closed), `end` (an
   optional day, the last day the work runs, in the site timezone, same
   meaning as `expires`), `uid`, `created`/`changed`. Not revisionable.
4. **Writes name an open case.** `AimScopeCaseEntity::validateSubject()`
   refuses a closed case ("case `case-x` is closed") and an ID-shaped
   subject that is no case ("no case `case-7f1c595e`"). A name that
   resolves to a new ID (core's `resolveSubject()`, unchanged) creates
   the case entity when the first fact is saved (on insert, not
   presave), open, with no end. This covers TODO.md's case subject
   constraint for sites with the module; the constraint's string-only
   form stays a core item.
5. **Closing retires; reopening un-retires.** Closing a case retires its
   live facts the way the expiry sweep does, with
   `superseded_by_reason` `{"decision":"case_closed","case":"<id>"}`.
   Reopening it un-retires the facts carrying that reason, unless their
   own `expires` has passed meanwhile. A fact's own `expires` is never
   written by the case. Recall needs no change: a closed case's facts are
   retired, so they are already out.
6. **The end date closes a case through core's sweep.** Core gets one
   seam: `AimMemoryManager::retireExpired()` invokes
   `hook_aim_expire(int $now)` after retiring expired facts. `aim_case`
   closes every open case whose `end` has passed. One crontab line
   (`aim:expire`) and its `hook_cron` stopgap cover both, so the module
   adds no cron of its own (ADR-0003).
7. **Permissions.** Writing a fact to a case stays `create case aim
   facts` (and creates the case on first use, point 4). Editing, closing
   and reopening a case is a new `manage aim cases` (`restrict access`).
   Viewing the case list is `view case aim facts` for now, the same flat
   permission as the facts: case access stays unbuilt (point 9).
8. **Install and uninstall.** Install creates an open case, label = ID,
   for every distinct `subject` in the case scope. Uninstall deletes the
   case entities and puts the core class back; facts keep their subject
   strings, and facts retired by a closed case stay retired (unretire is
   the existing admin action).
9. **Not in this ADR.** Members and case view access (ADR-0029,
   BACKLOG.md "Case scope access control"); the picker widget (waits on
   access, since a list leaks case IDs across viewers); case wording in
   `aim_brief` or the tools (`aim_remember` could say a case is closed
   or name its end: later); collapsing the subject fields (BACKLOG.md
   "One subject field pair": a string `id` keeps `subject` as is, so the
   trigger does not fire).

## Consequences

- Two case modes to test: core string-only, and entity-backed with
  `aim_case`. Kernel tests cover both.
- Another module altering the `case` plugin's class would conflict with
  `aim_case`; only one alter wins. Acceptable while `aim_case` is the
  only one; revisit if a second appears.
- A closed case refuses new facts, so a caller holding an old case ID
  gets an error to relay. The tool's wording for that is part of the
  build.
- Retirement provenance (`case_closed`) is what makes reopening safe;
  anything else that clears `superseded_by_reason` on those facts
  breaks reopen for them.
- Core's change is one hook invocation, plus a new `aim.api.php` (aim
  has none) documenting it and `hook_aim_scope_type_info_alter()`.

## Open questions

- Should a fact's own `expires` later than its case's end be refused or
  allowed? Proposed: allowed, the case end retires it first anyway.
- Does a case with no end need a reminder (e.g. `aim:status` listing
  open cases untouched for N days), given neglect is the main risk
  (open cases nobody closes)? Proposed: yes, as a status line, after
  the module works.
