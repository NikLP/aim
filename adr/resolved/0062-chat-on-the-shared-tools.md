# ADR-0062: Chat on the shared tools

**Status:** Resolved. Built 2026-10-25, except the upstream half of decision 2 (the
`ToolInputTransformEvent` stopgap is live; upstream is tool#3583125, a
runner-filled `?Invoker` method parameter, split out of tool#3583116 (the
invoker for access callbacks) 2026-10-28; TODO.md "Tools"). Measured as uid 1: `chatbot-converse` free 36/36
at 2.8 s a turn (3.6 s before), literal eval 20/23 (unchanged); as a plain
member (authenticated only, so the per-scope checks apply): 36/36 at 3.0 s,
20/23. Changes
[ADR-0013](0013-mcp-tool-exposure.md)'s "keep the two paths
separate" (its Decision, second bullet, and the Scope discipline risk) and
answers its open question on whether the chatbot should call the same tool
definitions. Touches [ADR-0008](0008-chatbot-integration-mechanism.md) (the
chat mechanism) and [ADR-0037](0037-transient-source-passages-for-grounding.md)
(grounding evidence).
**Date:** 2026-10-25

## In plain terms

Before: the site chat has its own recall and remember tools in
`aim_chatbot`, locked to `scope: site`, beside `aim_tool`'s
`aim_recall`/`aim_remember` that MCP, Drush and Tool API use. Two tools per
job, which drift.

After: there is one recall and one remember, `aim_tool`'s. The chat agent
calls them through `tool_ai_connector`, as it already calls `aim_literal`.
Everything chat needs is built into those tools: they work out who is
calling and pick the trust channel themselves, and recall says what kind
of date it returns. Chat's grounding evidence becomes the model's quote,
as over MCP. `aim_chatbot` keeps the agent,
the assistant, the block and the gate, and loses its two tools.

Gains: one place to fix recall and remember; chat gets the scope guide,
`facts` lists and `expires` handling for free. Costs: what chat can read
and write is set by the visitor's permissions, not by code, so a site
granting a chat role more scopes gives chat more scopes (Decision 1).

## Context

The chat tools were kept separate (ADR-0013) because chat visitors were
anonymous and the `scope: site` lock in code was the only thing keeping
other people's user facts out of their answers. Since then the chat widget
is closed to anonymous, and both tool paths run as the calling account
with no access bypass: recall checks each result against the per-scope
`view` permission (and `AimScopeUser`'s role-visibility matrix), remember
checks `create {scope} aim facts` per entry. The lock repeats what
permissions already do. Tool API's method tools (beta12, ADR-0044) made
`aim_tool`'s tools the obvious single home.

What the chat copies do that the shared tools don't yet:

1. **Trust channel.** Chat writes use `AimTrustPolicy::CHANNEL_CHAT`
   (shipped as `hold`: a visitor's fact waits for a reviewer).
   `aim_remember` hardcodes `CHANNEL_MCP`, whoever calls it.
2. **Grounding evidence.** The grounded check compares a fact with the text
   it came from. Over MCP the conversation happens in the client (Claude
   Desktop, Claude Code): the server only receives the tool call's
   arguments, so the client has to quote the conversation into
   `source_text`, and the server cannot tell a real quote from a made-up
   one. In chat the conversation happens on this site, in the agent's own
   history, so aim can read it directly.
   `AimChatbotSourceTextSubscriber` does that today for the chat tool.
3. **Dates.** Chat's recall lines say "true since" or "recorded"
   (`formatFactLine()`); the shared tool's `date` doesn't say which.
4. **Result count.** Chat recalls 5 facts, the shared tool defaults to 10.

## Decision

1. **Scope is gated by permissions, no lock (decided 2026-10-25).** Nothing
   assumes `site`: a role gets in chat whatever scopes it holds `view` and
   `create` for, the same as over MCP. `recall aim facts` and
   `remember aim facts` drop `restrict access: true`, since each only
   opens a tool whose every result and write is checked against the
   per-scope permissions; chat-facing roles get both. Reaching `/mcp`
   still needs `access mcp server` (only `administrator` on this site).
2. **The tool picks its trust channel from its caller.** Every Tool API
   caller identifies itself with an invoker: `tool_ai_connector` (an
   agent on this site), `mcp_server` (an MCP client), `drush`. `aim_remember`
   maps it: `tool_ai_connector` to `CHANNEL_CHAT`, `drush` to
   `CHANNEL_DRUSH`, anything else (MCP, direct PHP) to `CHANNEL_MCP`. The
   source follows: `chat:aim_remember`, `drush:aim_remember`, otherwise
   `tool:aim_remember` (the invoker doesn't name the agent; chat facts used
   to carry `chatbot:aim_chatbot`). Neither is a tool input, so no caller can claim another's.
   A `#[ToolMethod]` service does not receive the invoker today, so:
   - **Upstream:** ask Tool API to hand a method its invoker (for example
     a parameter typed `Invoker`, filled by the runner, never an input).
   - **Until then:** `aim_tool` reads it from Tool API's
     `ToolInputTransformEvent` (which carries the tool and its invoker)
     when `aim_remember`'s `facts` input is set; the service uses it for
     that one call and clears it. Deleted when the upstream change lands.
3. **Chat's evidence is the model's quote, as over MCP (decided
   2026-10-25).** `AimChatbotSourceTextSubscriber` and its unit test are
   deleted; the chat model passes `source_text` like any caller. Reading
   the history ourselves is cheap at runtime but leans on `ai_agents`
   internals nothing promises (event order, the wrapper's plugin ID, how
   it forwards inputs) and falls back silently when they change, and it
   buys little today: chat ships `hold`, so the check never promotes a
   chat fact, and this site runs the check in `shadow`. It comes back
   (as an `aim_tool` subscriber, registered only with `ai_agents`) if
   chat's rule loosens or the check moves to `enforce`.
4. **Recall says which date it is.** Each `aim_recall` result gains
   `date_kind`: `true_since` (from `asserted`) or `recorded` (from
   `created`). `formatFactLine()` and the structured result then say the
   same thing. Closes TODO.md's "`aim_recall`'s `date` doesn't say which
   date it is". An MCP client connected before the change rejects results
   until it reconnects (cached output schema).
5. **Chat keeps 5 results, for now, in config.** The agent's
   `tool_usage_limits` forces `limit: 5` on `tool:aim_recall`, hidden from
   the model. Revisit after the evals.
6. **One reply wording.** The shared tool's status note ("Waiting for staff
   review: recall will not return it until it is approved") and its
   permission error replace chat's own lines; the persona says how to put
   them to a visitor.
7. **`aim_chatbot` depends on `aim_tool` and `tool_ai_connector`** and ships
   its agent with `tool:aim_recall`/`tool:aim_remember`. Its two
   `#[FunctionCall]` plugins and their function group are deleted (PoC, no
   outside users). The eval command's forced mode and anything else naming
   `aim_chatbot:recall`/`aim_chatbot:remember` move to the new IDs.

## Consequences

- **A chat role's scopes are a permission decision.** Grant a member role
  `view case aim facts` and chat answers from case facts; the user scope's
  role-visibility matrix reaches chat the same way. The demo grants
  `site` only.
- **Each caller's starting trust is a setting.** The channel names who
  wrote; `aim.settings:trust_policy` says what that means. Shipped:
  `channels: {chat: hold}`; MCP and Drush follow `default`, then
  `default_trusted` (`false` on this site), and a reviewer's own fact
  starts trusted on any channel. No UI yet (TODO.md "Trust policy
  follow-ups"): `drush config:set aim.settings trust_policy.channels.mcp
  hold`, or a recipe's config action.
- **The chat model sees the full schemas** (`scope`, `subject`, `user`, the
  scope guide) and reads recall as JSON, not bullet lines. Measure with the
  literal eval (`gold.agentblind.yml --modes=tool`, baseline 20/23) and the
  chat eval before and after; nudge the persona only on a measured miss.
- **Chat's grounding score now rates the model's quote,** which the model
  writing the fact also chose. A reviewer reading the score should know
  that; it was the visitor's own words before.
- **Any agent on the site writes as chat.** An admin-side agent calling
  `aim_remember` gets `CHANNEL_CHAT`.
  That is the stricter channel; a site wanting otherwise sets a rule, and
  a second agent kind would need its own invoker or channel then.
- **Opening chat to anonymous** (the site's CLAUDE.md) now also needs
  `recall aim facts` on `anonymous`, besides `view site aim facts`.

## Rejected

- **Keep the chat tools, share code underneath.** Two schemas and two
  descriptions still drift.
- **A `channel` or `source` input.** Any caller could claim any channel.
- **Have `aim_chatbot` tell the tool it is chat** (a setter called from its
  subscriber, this ADR's first draft). Puts the decision outside the
  tool, which already has the caller's identity to hand.
- **Convert `aim_remember` back to a `#[Tool]` plugin class** to reach
  `getInvoker()`. Undoes ADR-0044's move for one value the upstream change
  provides.
