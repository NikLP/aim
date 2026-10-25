# AIM Tool - Developer Reference

Mechanism detail and gotchas for `aim_tool`. Pitch/requirements in
[README.md](README.md), operating rules in [CLAUDE.md](CLAUDE.md), root
module reference in [../../DEVELOPING.md](../../DEVELOPING.md).

---

## Tool API + MCP exposure

`aim_tool` ships the tools `aim_remember`/`aim_recall`/`aim_literal`
wrapping `AimMemoryManager` directly (any scope, gated on `remember aim facts`/
`recall aim facts`, not the admin UI's single `administer aim`).
`mcp_server_tool_bridge` exposes both over MCP via config-only
`mcp_tool_config` entities, shipped by `aim_tool_oauth` (`config/install`),
so MCP is opt-in: enable that module to turn it on. `aim_remember` takes
only `facts`, a list of one or more `{text, scope, subject?, expires?,
scope_fields?}` entries, saved in one call/bootstrap (a single-fact form
beside it was removed 2026-10-25: it duplicated every field and the two
descriptions drifted). `expires` (`YYYY-MM-DD`) retires the fact after that
day via `drush aim:expire` or `hook_cron` (ADR-0057). Each entry is saved
or reported on its own line. `source` and the trust channel are never
inputs: they follow the Tool API invoker (`AimRemember::CALLERS`, ADR-0062).
`tool_ai_connector` (an agent on this site: chat) writes `chat:aim_remember`
on the `chat` channel, `drush` writes `drush:aim_remember` on `drush`,
anything else (MCP, direct PHP) `tool:aim_remember` on `mcp`. A
`#[ToolMethod]` service is not given its invoker, so
`AimRememberInvokerSubscriber` catches it from `ToolInputTransformEvent`
when `facts` is set and hands it to the service, which uses it once and
clears it; delete the subscriber when Tool API passes the invoker to the
method.

Requires `drupal/mcp_server_tool_bridge:^1.0.0-beta2` +
`drupal/mcp_server:^2.0.0-beta3` together - `mcp_server_tool_bridge`'s
nested List/Map input schema support (needed for `aim_remember`'s `facts`
input) shipped in `beta2`, which itself requires `mcp_server ^2.0.0-beta3`,
one version ahead of `mcp_server`'s own `^2.0@beta` constraint. Also
requires core's `serialization` module (schema generation throws
`LogicException` without it).

Over MCP the Tool API plugin IDs are listed with a `tool_api__` prefix
(`tool_api__aim_recall`, `tool_api__aim_remember`; observed 2026-09-27 with
`mcp_server_tool_bridge` 1.0.0-beta3, `mcp_server` 2.0.0-beta5, `mcp/sdk`
0.8.1), so a client that cached the old bare names needs to refresh its
tool list. `aim_recall` always applies the site's `recall_max_distance`
(no per-call `max_distance` input, removed 2026-10-25: a caller passing 2
turned the cutoff off; `drush aim:recall --max-distance` stays for
tuning). `limit` is capped at `AimRecall::MAX_LIMIT` (25) by a `Range`
constraint, which the MCP schema advertises as `minimum`/`maximum` and the
server enforces before the method runs. An empty result reads "No
relevant facts found."

**Recall results are structured** (decided 2026-10-25 over a prefix per
line): `results` is a list of `{text, scope, subject, date, date_kind}`
(subject empty for site; `date` is `asserted` with `date_kind: true_since`,
else `created` with `date_kind: recorded`), declared with
`#[ToolOutput(definition: ListOutputDefinition)]`, which the MCP bridge
publishes as `outputSchema` and returns as `structuredContent`. Scope and
subject are there for the caller to act on, such as adding to a case by its
ID. A few more tokens per result than a formatted line (field names repeat),
bounded by the 25 cap. `aim_chatbot`'s recall keeps `formatFactLine()`'s
lines. An MCP client validates results against the schema it cached when it
connected, so a connector opened before an output change reports "Structured
content does not match the tool's output schema" until it reconnects.

**All three tools are `#[ToolMethod]` services**, not
`#[Tool]` plugin classes (tool 1.0.0-beta12, ADR-0044): `src/Tool/`,
registered in `aim_tool.services.yml`. Tool API builds each tool's inputs
from the method's parameters and `@param` text, so a description lives in
the docblock; `#[ToolInput]` only sets a label or a constraint the
inference can't (or, for `aim_remember`'s `facts`, a whole list-of-maps
definition, which no PHP type describes), and `#[ToolOutput]` declares the outputs (the methods
return an `ExecutableResult` to keep their own reply messages, so nothing is
inferred from the return type). The tool ID is on the attribute, so the
plugin IDs, MCP names and the recipe's `tool:aim_literal` are unchanged, and
`hook_tool_info_alter()` reaches method tools like plugin ones. A
`#[ToolMethod]` tool and a `#[Tool]` class with the same ID cannot coexist:
discovery skips the method one. `aim_remember`'s per-scope create check
stays inside the method, not in `#[ToolMethod]`'s `$access`: that runs
before the tool, so one entry in a scope the caller can't write would deny
the whole list instead of being reported and skipped.

**Descriptions have one source each.** The scope inputs' descriptions are
rewritten at discovery by `AimToolHooks::toolInfoAlter()` from
`AimScopeGuide` (`aim_tool.scope_guide`): recall gets a one-line list,
remember the full guide, both limited to the installed scopes, site first.
`AimToolHooks::scopeChanged()` clears the plugin caches on any
`aim_scope` insert, update or delete, so a scope added from the admin form
appears without a rebuild. The MCP one-liner per tool is the plugin's own
description: the `mcp_tool_config` entities ship no `description`, which
would override it ([McpToolConfigDeriver](../../../../contrib/mcp_server_tool_bridge/src/Plugin/Derivative/McpToolConfigDeriver.php)
falls back to the tool definition only when it is empty). The MCP server
instructions come from `aim_tool_oauth`, reading the same guide.

`aim_remember`'s reply adds "Waiting for staff review: recall will not
return it until it is approved." for an untrusted fact and "It retires
after YYYY-MM-DD." when `expires` is set, per fact in a batch too (the same
idea as `aim_chatbot`'s remember reply).

`mcp_server_tool_bridge` and `mcp_server_oauth`'s Composer package names
were previously self-doubled on drupal.org (`drupal/x-x`, an empty
metapackage stub) - fixed upstream; both now resolve under their plain
names.

## MCP OAuth

A remote MCP client with no Drupal session authenticates separately - see
[aim_tool_oauth's DEVELOPING.md](../aim_tool_oauth/DEVELOPING.md).
