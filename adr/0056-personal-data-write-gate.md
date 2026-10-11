# ADR-0056: No personal data stored unless authorised - a personal-data write gate

**Status:** Parked (2026-10-11, Nik: revisit personal data later) - proposed
2026-10-10, design only. Decision 7 (no stored passages) was built on its
own merits as part of ADR-0037 on 2026-10-11. Needs a
personal-data eval set first (decision 4). If accepted, replaces
[ADR-0037](0037-transient-source-passages-for-grounding.md) decisions 1-4
(decision 7 below).
**Date:** 2026-10-10

## In plain terms

**Before:** any fact can carry a person's name, email or phone number. The
only write checks are a length limit and a no-markup rule. The README says
Guardrails filter PII; they do not.

**After:** a fact that names or identifies a private person is refused at
write time, unless the write is authorised: a `user`-scope fact about its own
subject, or a human holding a new permission who stores it on purpose. A
model is never the one who authorises.

**Gains:** personal data in aim's database is there by decision, not by
accident; GDPR exposure shrinks to facts someone chose to keep; erasure can
find them. With decision 7, raw source text (the densest personal data) is
never stored at all.

**Costs:** one extra model call per write (about 0.35 s on hosted Jev; shared
with the grounded check when there is a passage); some useful facts are
refused and must be restated with a role instead of a name; a write fails
while the checking model is down.

## Context

Nik's rule (2026-10-10, prompted by a GDPR reminder): aim should not save
names or other personal data unless authorised, and that needs to be a gate.

Today:

- `aim_write_guardrails` holds `aim_max_length` and `aim_no_markup` only.
  `AimMemoryManager::runGuardrails()` already supports what a personal-data
  check needs: `StopResult` refuses the write, and a
  `NonDeterministicGuardrailInterface` plugin gets the provider manager for
  a model call. It runs from `saveFact()`'s `validate()`, so every write route
  (Drush, ingest form, chat tools, MCP, default content import) passes
  through it.
- One set serves every scope; guardrail plugins see text only, not the
  scope or subject.
- The extraction prompt already says "Do not add names, organizations or
  other identifying details that the fact does not need". That is a prompt,
  not a gate, and chat and MCP writes never pass through extraction.
- `user` scope is personal data by design: the fact is about one real
  account ([ADR-0007](resolved/0007-user-scope-requires-real-account.md)).
- Two agreed designs would store personal data: the `aim_rejection`
  quarantine (TODO.md "Lossy rejection") keeps the rejected text, and
  ADR-0037's `aim_fact_source` table keeps raw conversation for up to 7
  days.
- On this site, source text already reaches hosted models (extraction and
  chat on amazeeio, decisions on Jev). This ADR is about what gets stored in
  aim's database, not what a model sees; the sovereignty posture
  ([ADR-0004](resolved/0004-sovereignty-and-poc-build-order.md)) is unchanged.

## Decision

**1. What counts.** A person's name, contact details (email, phone, postal
address), account or ID numbers, and anything else that identifies a
private individual. Not personal data: organizations, and roles without a
name ("the branch manager", "a staff member").

**2. What authorises it.**
- A `user`-scope fact about its own subject. Other people named in it (a
  family member, a colleague) are not authorised.
- A human writer holding `store personal data in aim memory` (new,
  `restrict access`), on a human-authored route only: the admin fact form
  and Drush with `--uid`. The chat tools, MCP tools and extraction cannot
  use it; the model wrote that text, not the person.
- An authorised fact is marked (`personal_data` boolean on `aim_fact`) with
  an audit line, so a retention or erasure job can find it.

**3. Where it runs.** A second guardrail set, `aim_personal_data`, run by
`runGuardrails()` after `aim_write_guardrails` unless the write is
authorised. It checks `text`, `subject` (except a `user`-scope subject,
which is an account) and `source`. For `user` scope the model check gets
the subject's display name as context and asks about anyone else.

**4. Two tiers.**
- **Patterns** (core `RegexpGuardrail`, local, no call): email addresses,
  phone numbers, card and IBAN numbers. Skipped for `user` scope, where the
  subject's own contact details are authorised. A side effect worth having:
  an organization's raw phone or email in a fact is refused, which pushes
  it into a literal (`[literal:key]` tokens pass the pattern).
- **Names** (an aim guardrail plugin calling the decision backend, one Noul
  question): "Does the fact name or identify a private individual?"; for
  `user` scope, "anyone other than the subject?". Hosted Jev on this site,
  synthetic data only; a site with real data needs a local model
  ([ADR-0038](0038-local-decision-models-parked.md)). Build an eval set
  first: `pii` task in `decision-eval.php`, named and unnamed people, roles,
  organizations, and the subject-versus-third-party case.

**5. On failure, refuse, never redact.** `StopResult` with a message telling
the caller to restate the fact with a role instead of a name; a chat or MCP
agent can retry. A silent rewrite could change what the fact means. A
refused candidate goes to `aim_rejection` with reason `personal_data` and
no text: hash, scope, caller and time only. If the name check cannot run
(provider down, timeout), refuse the write: patterns alone miss names.

**6. Prevention first.** Tighten the extraction prompt: in `site`, `role`,
`case` and `entity` facts, replace a private individual's name with their
role. Extraction's `user`-scope `subject` stays a name by design; the caller
resolves it to an account.

**7. Source passages are not stored (replaces ADR-0037 decisions 1-4).**
ADR-0037 stored passages because the check ran later in the queue, which
made sense with local models at 10-14 s a call. Hosted Jev answers in
0.35 s, so the grounded check can run at write time while the source text
is still in memory: in the extraction call, the chat tool (the visitor's
message plus the previous turn, per ADR-0037's 2026-10-10 eval) and MCP
`source_text`. One call asks both questions when a passage exists. The fact
keeps the verdict, score and passage hash; the text is never saved. No
`aim_fact_source` table, no retention sweep, no `view aim fact source`
permission. Lost: re-checking a fact later against its passage.

## Consequences

- `runGuardrails()`'s docblock ("never makes an LLM call") stops being true.
  Merged text currently runs Guardrails twice (TODO.md "Fact lifecycle");
  with a model-backed guardrail that becomes two calls, so validate once and
  reuse the result, as that item already says.
- Demo data: the `user`-scope Sam facts (uid 1) pass. The `site` fact "You
  can reach the library by phone or at hello@harbourside-library.example"
  would be refused by the pattern tier; move the address into a literal.
- Literal values (a staff member's direct line) are out of scope here; the
  same rule should apply in `literals`, its own ADR.
- `aim_rejection`'s design changes: no text column for `personal_data`
  rejections.

## Alternatives considered

- **Redact instead of refuse.** Rejected: a rewritten fact can mean
  something else, and nobody sees the change.
- **Store, flag and review.** Rejected: the rule is not to store, and nobody
  reviews (Nik, 2026-10-10: neglect is the main risk).
- **Check in the queue after saving.** Rejected: the data is already in the
  table and, with `index_directly`, the vector index.
- **The extraction prompt only.** Rejected: chat and MCP writes skip
  extraction, and a prompt is not a gate.
- **A local named-entity recognizer.** Not ruled out; nothing Drupal-native
  exists, and the decision model is already wired. Revisit for sovereign
  sites.

## Open questions

- Fail closed when the checker is down (decision 5) blocks every write
  during an outage. Acceptable for a PoC; a site may want "save untrusted"
  instead, which breaks the rule.
- Public names (a staff name already published on the website): covered by
  the permission today; a per-site allowlist may be kinder.
- Retention for authorised `personal_data` facts, with the retired-facts
  retention item (TODO.md, ADR-0022).
