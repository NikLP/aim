# ADR-0058: htmx 4 for self-saving admin controls

**Status:** Proposed 2026-10-13 - feasibility study only, nothing built or installed.
**Date:** 2026-10-13

## Context

Shawn Duncan's post "Interactive Forms with htmx 4" (Drupal Patterns)
describes a form whose checkboxes save on click. The Form API already lets
any element act as a submit button: `#executes_submit_callback` plus an
element-level `#submit` (and `#validate`), read by `FormBuilder` from the
triggering element. htmx posts the form on change, the submit handler
detects an htmx request (`HtmxRequestInfoTrait::isHtmxRequest()`), drops
the status message, and returns a targeted partial (htmx 4's
`<hx-partial>`) that updates the page. htmx 4 extensions register
themselves in JS, so they can be loaded as Drupal libraries.

Core on this site (11.4) ships `Drupal\Core\Htmx` (the `Htmx` helper and
`HtmxRequestInfoTrait`) alongside the existing `#ajax` system, which core
keeps until contrib has migrated. Core's integration targets htmx 2; the
move to htmx 4 is Drupal 12 work, and htmx 4 is still beta upstream.

Aim's main risk is neglect ([TODO.md](../TODO.md)): review and admin steps
that need extra clicks do not get done.

## Candidates

- **Per-row trusted toggle on the `aim_facts` view.** Replaces ticking rows
  and running the Trust/Untrust bulk action with one click and an in-place
  update. Gated by the existing `trust {scope} aim facts` operation. A View
  row is not a form, so the `#executes_submit_callback` trick does not
  apply: it needs a Views field plus a small controller or form.
- **User-scope role-visibility matrix** (the user scope's settings on its
  edit form, `/admin/config/aim/scopes/user/edit`, since ADR-0063). A
  grid of checkboxes plus a Save button, the post's use case almost
  exactly. It drives real access, so saving on change with the result
  echoed back beats a Save button that can be forgotten. It is now part of
  an entity form, not a `ConfigFormBase`.

Not worth it: `AimScopeForm`'s plugin selector (a dependent sub-form swap
that works with `#ajax` and is rarely used) and the `aim.settings` form
(set once; auto-save gains nothing).

## Open questions

- Which htmx version core 11.4 bundles.
- Whether htmx 4 can be added as a plain library and coexist with core's
  htmx 2 integration (its attributes, `drupalSettings` and the `Htmx`
  class may emit htmx 2 forms).
- Whether the contrib `htmx` module's htmx 4 loading is reusable.
- Whether waiting for Drupal 12 core support is the cheaper path.

## Decision

None yet. Study only; build nothing until the open questions are answered
and the owner says go. If it proceeds, prototype the role-visibility
matrix first (closest match to the post), then the trusted toggle.
