# ADR-0010: Drupal-native AI agent memory infrastructure (original rationale)

**Date:** 2026-09-08. Folded into this directory 2026-09-10 - originally
authored and maintained as a standalone root-level document (outside the
module, alongside the site shell) before this `adr/` structure existed.
**Status:** Accepted as founding rationale, not as a live source of
decisions. The binding architectural calls made here were subsequently split
out into ADR-0001 through ADR-0009 in this directory, which supersede this
document wherever they overlap - see [0000-index.md](0000-index.md). This
one is retained for the market comparison, risk analysis, and original
context those are downstream of. Distinct venture; not a feature of, or
dependency on, the `annotations` suite. Where that suite is referenced below
it's for architectural kinship only.
**Updated same day:** folded in a verified read of `ai_vdb_provider_mariadb`'s
actual source (embedding/insert mechanics), the concrete Tool API/MCP write
path, a full AI-dependency map distinguishing reasoning calls from embedding
calls, a zero-API-key PoC build path, memory-poisoning risk and the
Guardrails/CCC governance layer, write-concurrency mitigation, a caching/CDN
read, a Kenkeep comparison, a correction to Anthropic subscription-vs-API-key
mechanics, and a second product surface (generative/planning use via
Recipes).

**Updated 2026-09-10:** the original market read (Mem0 + Zep only) was too
thin to answer "is this worth continuing as more than a curio" - widened to
seven systems with concrete, checkable parity targets instead of
architectural bullet points (new "Appraisal widened" subsection below); built
a real benchmarking harness (`drush aim:benchmark`, `aim`'s own repo) and took
the first actual retrieval-latency numbers, replacing open question 5's
"asserted safe, never measured" state with a real (if small-scale) result -
see that open question's update below.

## Context

### The premise

Businesses are going to want in-house agentic memory as model costs fall,
the same way they wanted in-house CMSs once web publishing got cheap. Rather
than wait for that market and buy in later, the question is whether it's
buildable in Drupal now, cheaply enough to be worth having ready.

### Why SQL, not a bolted-on vector service

A vendor-published comparison (Memori/GibsonAI's own blog, corroborated only
by SEO reposts of the same figures, so treat the specific numbers as
marketing not benchmark) claims 80-90% cost savings running agent memory on
SQL instead of a dedicated vector database, plus simpler ops (one service
instead of 3-5) and full transparency versus a vector DB's black-box
retrieval. The vendor-specific numbers don't hold up to scrutiny, but the
structural argument - SQL is mature, queryable, and already the substrate
most sites run on - does, and it's stronger for Drupal specifically than for
a generic app, because Drupal already has structured content, permissions,
and revisioning sitting on top of that SQL layer.

### The tech gap is now closed

`drupal/ai_vdb_provider_mariadb` (installed in `dotdev`'s `composer.json`,
not yet enabled) wraps MariaDB's native `VECTOR` type and HNSW-based
`VECTOR INDEX` (MariaDB 11.7+, 11.8 LTS recommended - `dotdev`'s `.ddev`
config already pins 11.8) as a VDB provider for the `drupal/ai` module and
Search API. It stores vectors in the same database as Drupal's own tables,
with full transaction support in its default mode. Status: security-covered
stable release (v1.0.1), single maintainer (Juan Martinez, who also sells
consulting on top of it), ~103 sites reporting usage, light issue queue (6
total, 2 open). Plain MySQL doesn't have the equivalent - its `VECTOR` type
exists in Community Edition but indexed ANN search is HeatWave-only
(Oracle Cloud managed service, not self-hostable), so MariaDB specifically is
the enabling dependency, not "MySQL-family databases" generally.

Existing Drupal AI ecosystem modules (`ai_agents`, `ai_search`) do
tool-calling and content indexing. Neither implements memory in the sense
below - extraction, consolidation, decay. That layer doesn't exist yet
anywhere in the ecosystem; it's the actual product.

### What the current memory-tooling market does

Researched Mem0 and Zep as the two most-cited 2026 frameworks.

**Mem0** scopes every memory write by up to four composable identifiers -
`user_id`, `agent_id`, `run_id`/`session_id`, `org_id`/`app_id` - and merges
across them at query time in priority order (session over user over org).
Its pipeline is single-pass hierarchical extraction (raw interaction →
candidate facts) feeding multi-signal retrieval. Its claimed SOC 2 (Type 1),
HIPAA-readiness, and GDPR compliance are organizational/process
certifications - audited access control, encryption, incident response, a
signed BAA, a DPA and erasure support - not evidence of anything unusual in
the memory algorithm itself. Worth noting because a self-hosted Drupal build
starts closer to this bar than a SaaS vendor does by construction: data
residency and access control are already answered by "it never left your
infrastructure."

**Zep**'s 2026 headline architecture is the **Context Lake** - the
data-lake pattern applied to agent context. Millions of per-subject context
graphs (chat, documents, events, business data, plus the relationships
between them, temporally structured), governed and served as one system on
top of a proprietary Context Graph Engine. Three ideas worth taking:

1. **Hot/warm/cold tiering** - active graphs in RAM, idle ones snapshotted to
   cheap object storage, rehydrated on demand. Cost tracks active graphs, not
   total volume.
2. **Provenance as a first-class edge** - every fact traces back to the
   source episode that produced it, not bolted-on metadata.
3. **Governance lives in the data layer** - ABAC, multi-tenant isolation,
   retention, and audit gate every read/write, across every graph, not
   implemented per-application. Zep claims sub-200ms p95 retrieval regardless
   of graph size or count.

### Appraisal widened, 2026-09-10 - five more systems, real parity targets

Two frameworks (Mem0, Zep) is not a market appraisal, it's a sanity check.
Widened to seven, via a background research pass, specifically to answer
whether `aim` is worth continuing as a useful tool rather than an expensive
curio:

| System | Mechanism | Number |
| --- | --- | --- |
| Mem0 | LLM extracts entity/preference triples per turn, ADD/UPDATE/DELETE against retrieved neighbors | p95 search 0.20-1.44s |
| Zep/Graphiti | facts as bi-temporal graph edges (`valid_at`/`invalid_at` + `created_at`/`expired_at`), contradictions invalidate rather than delete | p95 0.632s |
| Letta/MemGPT | OS-paging metaphor - Core/Recall/Archival tiers, **the agent itself** decides what moves between tiers mid-turn | no clean accuracy number; 1M+ agents in production at Bilt |
| OpenAI ChatGPT/API memory | background job periodically rewrites a readable, user-editable profile from chat history | closed system, no public benchmark |
| LangGraph/LangMem | DIY SDK, storage-agnostic, three memory types | reported p95 59.8s - impractical as shipped |
| Cognee | Extract-Cognify-Load pipeline into one graph+vector+relational store, 14 retrieval modes | no independently verified accuracy number |
| Supermemory | hosted, aggressive context compression | 95% Recall@15 at ~720 tokens (self-reported) |

**Every single-number claim above is marketing, confirmed directly, not a
cautious hedge:** Zep's own published rebuttal of Mem0's LoCoMo/LongMemEval
scores shows the whole space uses incompatible judge models, token budgets,
and competitor re-implementations - none of these numbers are comparable to
each other, let alone to a number `aim` might produce.

**2026-09-11 confirming instance:** Mem0's README now headlines a "New
Memory Algorithm (April 2026)" - LoCoMo 71.4 to 92.5, LongMemEval 67.8 to
94.4 - attributed to dropping per-pair LLM ADD/UPDATE/DELETE/NONE decisions
for single-pass additive extraction plus entity linking and BM25 fusion
(read directly from `mem0ai/mem0` @ `a488e19`, not taken from marketing
copy - see [ADR-0012](0012-fact-relation-graph.md)). The README's own
fine print: "Scores reflect Mem0's managed platform, which includes
proprietary optimizations not available in the open-source SDK;
open-source users should expect directionally similar gains but not
identical numbers." That is the vendor conceding, in their own words, the
exact point this section already makes - a number attached to "Mem0"
is not one number, and the OSS code anyone can actually read is not the
thing the benchmark was run against.

**Parity targets - concrete and checkable, replacing the vaguer
"architecturally kin to Mem0/Zep" framing used elsewhere in this document:**

1. A disclosed-protocol benchmark slice (even 50-100 LongMemEval-style
   questions, methodology stated) beats an undisclosed 90%+ vendor claim on
   credibility alone, given the finding above.
2. p95 recall latency under ~1s (Mem0: 0.2-1.44s, Zep: 0.632s) - `aim`'s
   local MariaDB HNSW (no network hop to a hosted vector DB) is structurally
   well placed for this. First real measurement taken 2026-09-10, see open
   question 5 below.
3. Retrieved-context token efficiency: recall payload under roughly 2-5% of
   full-history token count for equivalent answer quality.
4. Knowledge-update correctness: after a fact changes, recall returns the new
   fact, not the stale one too - the `related`/`expires` supersede mechanism
   (ADR-0005 in `aim`'s own repo) is the right shape, never benchmarked
   against this.
5. Abstention correctness: return "no memory found" rather than hallucinate
   when nothing relevant exists - unverified today.
6. Provenance on invalidation: record *which* fact/write caused a
   supersession, not just that one happened - `related` records the edge but
   not the cause. Closeable with one added field on `aim_fact` (a
   `related_reason` alongside `related`/`expires`, populated at
   `consolidateFact()`'s existing call site), not a graph database or a new
   entity type - a full "event fact" type was considered and is bigger scope
   than it looks: `aim_fact` is single-bundle today, and a proper audit trail
   is already planned via ADR-0002's deferred Content Moderation revisioning,
   which a bespoke event type would likely duplicate once built.
7. Bi-temporal distinction: separate "when a fact stopped being true in
   reality" (valid-time) from "when the system learned that" (transaction
   time) - `aim` has `created` and `expires`, but neither is independently
   assertable as the former; both default to whatever the row's own
   write-time happens to be, so a fact reported late (e.g. "I moved three
   months ago") has no way to record the real-world date separately from
   today's write. **Resolved 2026-09-10:** rather than a full bi-temporal
   model, a single nullable `asserted` field on `aim_fact` (when the fact
   became true in reality; defaults to `created` when a caller doesn't know
   better) closes the practical gap. See ADR-0001 in this directory for the
   field itself once built.

**Not worth chasing at PoC/single-maintainer scale:** Graphiti's full
temporal-graph engine, Letta's million-agent concurrency claims, Supermemory's
hosted-scale latency SLAs - funded-infrastructure bets, not a gap this
project should try to close.

**Genuinely novel elsewhere, no `aim` equivalent, beyond the
already-identified role-memory gap:** Letta's agent-self-directed mid-turn
memory paging (`aim`'s writes are always pipeline/tool-call-decided, never
the model choosing what to evict - noted, not being chased, see above);
OpenAI's user-facing editable memory summary (corroborates `aim` CLAUDE.md's
existing "fact verification as a user-facing feature" idea, under "Ideas
raised, not designed," as the actual adoption driver, not just a governance
nicety).

### Mapping onto Drupal primitives

Drupal already has most of what both frameworks build custom infrastructure
for:

| Concept (Mem0/Zep) | Drupal equivalent |
| --- | --- |
| Graph of context objects + relationships | Entity Reference fields - entities already form a graph; no separate graph DB needed |
| Vector embedding per object | `VECTOR` field via `ai_vdb_provider_mariadb`, on the same entity |
| `user_id`/`agent_id`/`run_id`/`org_id` scoping | User entity, Role, a case/session entity, and a single site-global bundle - see scope model below |
| Provenance (fact → source episode) | Entity reference to the source content/interaction, for free |
| Audit trail | Revisioning / Content Moderation - no custom build |
| Governed at the data layer | Permissions/roles gate access natively; a human-review workflow state (draft → trusted) gates whether an extracted memory is retrievable at all - genuinely ahead of both frameworks, which don't offer human-in-the-loop approval as a first-class concept |
| Policy versioning | Config export/sync - retention rules, extraction prompts become deployable config |

None of this requires new infrastructure classes. It requires an entity/bundle
schema and the extraction/consolidation/decay pipeline as a plugin system.

**2026-09-11 external validation of the "no separate graph DB" row:**
Mem0 itself deleted its Neo4j-backed Graph Memory feature (`graph_memory.ts`,
`graphs/tools.ts`, `graphs/utils.ts`, and their test suites - confirmed via
`git log`/`git show` on `mem0ai/mem0` @ `a488e19`, not a marketing claim) in
the same April 2026 rewrite that introduced entity linking, replacing a
dedicated graph database with a second plain vector-store collection of
extracted entities pointing back at the facts that mention them. A team with
that much more resourcing than `aim` converged on the same call this row
already made - a lightweight entity/reference structure over a general
document store beats standing up a separate graph engine. See
[ADR-0012](0012-fact-relation-graph.md) for the retrieval-side mechanism.

### Scope model - four categories, mapped to Mem0's dimensions

- **User/developer memory** - `user_id`-equivalent scope, tied to Drupal's
  real User entity, permission-gated per account. Architecturally kin to what
  `annotations` already does (structured, per-content commentary) - the
  generalization is agent-*extracted* facts about a user rather than only
  human-authored ones. Not a dependency on that suite, just the same shape.
- **Role memory** - no direct equivalent in Mem0 (`org_id` is flat, not
  role-shaped) or Zep. Memory visible to everyone holding a role - "what
  Editors collectively know," "what Support agents collectively know" -
  falls out of Drupal's existing role system for free. This is the one
  category with no off-the-shelf competitor doing it natively; candidate
  differentiator if this becomes a real product.
- **Site memory** - one global/org-scoped graph, roughly Mem0's `org_id` or
  Zep's single tenant-lake. Facts about the site itself (structure, policy,
  editorial decisions) any agent acting on the site can draw from.
- **Support tracking** - `run_id`/session-scoped episodic memory, but
  modeled as an actual entity with real workflow states (open → assigned →
  resolved) rather than a bare session-ID string. Where Zep's "every fact
  traces to its source episode" becomes literal: the source is a content
  entity with its own revision history, not a synthetic pointer.

### The request-lifecycle question

Two AI-dependent steps exist in the pipeline, not one, and they have
different sovereignty implications:

1. **Embedding generation** (for both extraction and the similarity check
   that drives consolidation) - comparatively cheap to self-host. Small
   dedicated embedding models run acceptably on CPU or modest GPU.
2. **Extraction** (raw interaction → candidate facts) and the **"smart" part
   of consolidation** (merge/supersede/conflict-resolution decisions, e.g.
   "user said London, now says Manchester - supersede or both true at
   different times?") - this genuinely needs LLM reasoning. A cosine-similarity
   threshold plus recency-wins heuristic can handle a good share of
   consolidation without any model call, but the nuanced cases need one.

For a sovereign deployment (data must not leave the customer's
infrastructure - the same requirement that keeps surfacing across this
program's other sovereignty-flavored work), step 2 means a **local model**,
not a SaaS call. `drupal/ai`'s provider abstraction already covers Ollama
and similar, so this is a provider-configuration decision, not new plumbing
- but it is a real infrastructure cost (GPU or capable CPU box for the
extraction/consolidation cron jobs) that a sovereign customer has to budget
for, not a checkbox to tick.

Separately: Drupal is request/response, not a long-running process, which
looks like a mismatch against Zep's "hot graph in RAM" model. It mostly
isn't one in practice. Every real Drupal deployment already runs system
cron (crontab → `drush cron`), fully decoupled from HTTP requests - the
natural home for extraction, consolidation, and decay, none of which need
sub-second reaction. Drupal's lock service prevents overlapping cron runs,
so there's no data-integrity floor on frequency; an overlapping invocation
just no-ops rather than double-processing. The one case that does need
something extra is low-latency inline extraction during a live chat turn
without blocking the HTTP response - that wants a dedicated async queue
worker (`drush queue:run <queue>` on its own crontab entry, or a
systemd-managed loop), not a bespoke companion server. `\Drupal::queue()`'s
claim/lease mechanism is safe for frequent, even overlapping, invocation, so
this dedicated runner can run every 1-5 minutes without risk - the real
limiting factor is external (model API rate limits, or local GPU capacity),
not anything Drupal imposes. Mixing this workload into the site's *general*
`hook_cron` (typically run every 15-60 min for lightweight housekeeping) is
the wrong move - it risks starving unrelated cron tasks when an extraction
run takes long. A dedicated queue with its own crontab entry avoids that
entirely.

Retrieval-side latency (Zep's sub-200ms claim) is a separate concern from
the above. An indexed HNSW query against a `VECTOR` column is an ordinary
SQL query - single-digit-to-low-double-digit ms at reasonable graph sizes,
comfortably inside a page request. The real latency risk is computing the
*query* embedding synchronously via an external provider on every request;
mitigated by caching common query embeddings or keeping the embedding model
local and fast, not a property of the SQL layer itself. **Confirmed, not
just risked, 2026-09-10:** see open question 5 below - this is exactly what
the first real benchmark measured.

### Vector field population - verified against the module's actual source

Not a special field an editor fills in, and no vector is ever hand-authored.
Read `MariaDBProvider.php` and `MariaDBVectorClient.php` directly rather than
inferring from the README. The provider calls
`$embedding_strategy->getEmbedding($engine, $text, $config)`, delegating to
`ai_search`'s `EmbeddingStrategyInterface`, which calls out to whichever
embeddings-capable provider is configured in `drupal/ai` (the module's own
rate-limit UI is written specifically against OpenAI's account-limits page,
though the provider is swappable to anything `ai` supports). The returned
float array is formatted as a MariaDB vector literal (`[0.123,0.456,...]`)
and written via a parameterized `INSERT ... VALUES (..., VECTOR_STRING)` into
the `embedding VECTOR(N)` column - confirmed directly in
`insertIntoCollection()`/`createCollection()`.

As shipped, this whole flow is Search API's indexing pipeline: configure an
index with the MariaDB-vector backend, choose which fields to embed, and
indexing (cron-triggered or manual reindex) computes and writes the vectors.
For the memory pipeline specifically, two options: (a) model memory-fact
entities as ordinary Search API-indexed content and reuse that pipeline
as-is - less code, but tied to Search API's reindex cadence rather than "this
one fact, right now"; or (b) call the same `EmbeddingStrategyInterface`
directly inside the extraction/consolidation queue worker and write the
`VECTOR` field at consolidation time, skipping the Search API detour
entirely. Given the pipeline already has a dedicated queue worker (see
above), (b) is the natural fit - lower latency, same underlying mechanism,
just called directly instead of through Search API's indexing abstraction.
Search API's own flow remains the right tool if the site also wants to index
regular *content* for retrieval alongside memory.

### AI dependency map - what actually needs a model, and which kind

Easy to lose track of which step needs what. Not one "AI," several, with very
different cost/sovereignty profiles:

| Step | What it needs | Cost/sovereignty |
| --- | --- | --- |
| Discovery conversation (spec-gathering) | Full reasoning-grade LLM | Expensive |
| Extraction (conversation → candidate facts) | Full reasoning-grade LLM | Expensive |
| Consolidation - similarity-threshold cases | Vector math only | Free, no model call |
| Consolidation - conflict/merge decisions | Reasoning-grade LLM | Expensive |
| Embedding generation (every write *and* every query) | Small dedicated embedding model | Cheap, genuinely local-friendly |
| Recipe generation (spec → buildable config) | Reasoning-grade LLM, code-gen capable | Expensive |
| Annotation content generation | Reasoning-grade LLM | Expensive |
| The agent later *consuming* memory (support bot, `ai_agents`, Claude Code) | Reasoning-grade LLM | Expensive - this is the consumer, not the memory system itself |

Only the embedding row is cheap and trivially self-hostable regardless of
who's asking. Everything else is a real reasoning call, and there are five or
six of them scattered through a single site build, not one.

### Building and testing without any API key

The reasoning steps above don't require `drupal/ai` to be wired to anything.
For a proof of concept, an interactive Claude Code session can do the
reasoning directly - read the conversation, decide what to extract, decide
how to consolidate, write the result straight into the database via `drush`/
SQL - without the Drupal site itself calling out to any provider. Embeddings
are the one step that can't be done by reasoning (a vector is a specific
model's fixed-dimension output space; future queries have to land in the same
space for cosine similarity to mean anything) - but "needs a real embedding
model" doesn't mean "needs a paid API key." A local Ollama container, added
as a DDEV service, running something like `nomic-embed-text`, produces real
embeddings with no account and no cost. The whole round trip - conversation →
extraction → consolidation → embedding → storage → retrieval - is buildable
and testable today with zero API keys: Claude Code for every reasoning step,
local Ollama for the one step that needs an actual embedding model.

This only covers the *interactive* PoC. The point of a shipped product is
that extraction/consolidation happen unattended, on cron, with nobody driving
the reasoning by hand each time - and that does need *some* model configured
in `drupal/ai` permanently. That model can still be the same local Ollama
instance (still zero API key), but in practice a hosted frontier model tends
to be materially more reliable than a self-hosted one at complex structured
tasks - generating valid Recipe config, multi-step tool orchestration - so a
paid API key is a likely eventual choice on capability grounds, not an
architectural requirement. Tool-calling itself isn't the trigger; unattended
operation combined with task complexity is.

**Correction to the earlier "bring your own subscription" framing:** as of
April 4, 2026, Anthropic explicitly prohibits using Claude Pro/Max
subscription OAuth for any third-party tool or integration - subscription
quota only covers Claude Code, claude.ai, and Desktop. A Drupal `ai` provider
calling Claude needs a real Anthropic Console API key, billed per-token,
separate from a chat subscription. BYOK here has to mean "bring your own API
key" (or self-host via Ollama), not "bring your own chat subscription" - this
changes the cost framing for anyone who already pays for personal Claude use
and assumes that covers it.

### Write concurrency and load, for a multi-user (10+) scenario

The raw user count isn't the risk - 10 concurrent users is trivial for
MariaDB in general. The specific risk is that HNSW indexes are meaningfully
more expensive to update on write than a normal B-tree index (every insert
traverses the graph to find its place and update neighbor links) - true of
this index family generally, not a MariaDB-specific flaw. That only becomes a
real problem if writes land inline on a live user-facing request, where
concurrent users could contend on the index and slow down unrelated page
loads. Mitigated entirely within the main database, no external DB needed:

- **Async by design** - the Queue+cron pipeline already decided means vector
  writes never happen on a live request.
- **Bounded worker concurrency** - the number of queue workers touching the
  vector table is a deliberate choice, not a function of how many users
  happen to be active.
- **Batch writes** - accumulate candidate facts over a processing window and
  insert in batches rather than one INSERT-plus-index-update per fact.
- **Tune `M` per scope** - MariaDB's `VECTOR INDEX` trades index quality for
  write/storage cost; low-value scopes (case memory rarely re-queried) can
  run a cheaper index than something queried constantly (site memory).
- **Decay is a write-cost lever, not just tidiness** - a smaller, pruned
  graph is cheaper to update *and* query.
- **Read replicas** for the retrieval side, if reads rather than writes turn
  out to be the bottleneck - standard MariaDB replication, no special
  handling needed for this table.

### Caching and CDN

A CDN doesn't fit here - it caches public, cacheable HTTP responses at the
edge, and memory retrieval is the opposite profile: personalized, per-user or
per-role, and needs to reflect writes that may have landed seconds ago.
Putting it behind a CDN means serving stale memory to an agent.

What does apply is an application-level cache - Drupal's own cache API, or
Redis/Valkey in front of it - for hot, frequently-retrieved sets. This is the
Drupal-native version of Zep's "hot graph in RAM" idea, achieved with
ordinary cache tags rather than bespoke tiering. Worth separating two kinds
of retrieval here too: structural, scoped lookups ("all memory for case X")
are plain indexed `WHERE` queries, cheap and cacheable the normal Drupal way.
Vector search is only needed for fuzzy/semantic retrieval - a narrower slice
of total query volume than it looks like from outside.

### Memory poisoning and contamination - a different risk than sovereignty solves

Sovereign/local AI mitigates *confidentiality* (data never leaves the site's
infrastructure). It does nothing for *integrity* - a local model can
hallucinate a fact or be manipulated into writing a false one exactly as
easily as a hosted one. This isn't a minor caveat: memory poisoning is an
actively studied 2026 attack class specifically because persistent memory
makes it worse than ordinary prompt injection - a poisoned entry doesn't
expire when the conversation ends, it sits dormant and can trigger on an
unrelated interaction weeks later. Research on MINJA-style attacks reports
injection success rates above 95% against production agents; indirect
injection - malicious instructions arriving via retrieved/ingested content
rather than direct user input - is the larger, more consequential category.
Vector embeddings themselves can be crafted to look legitimate to retrieval
while carrying corrupted semantics. The EU AI Act's high-risk obligations (in
force since August 2026) explicitly name adversarial robustness - prompt
injection and data poisoning - as a cybersecurity requirement, not just
hygiene, for systems this could plausibly be classified under.

**Mitigation: run this through Guardrails, not around it.** `drupal/ai`'s
Guardrails submodule (production-ready since AI 1.3.0) does plugin-based
input/output filtering - input-phase blocks prompt injection and forbidden
topics before content reaches the model, output-phase filters hallucinations
and PII leakage (emails, phone numbers, IBANs, card numbers). Every candidate
fact the extraction step proposes is untrusted input and should run through
Guardrails before it's written anywhere - an automated first pass sitting in
front of the draft-to-trusted Content Moderation gate already decided.
Two-layer defense, not either/or: Guardrails catches the obvious cases fast,
human review catches the judgment calls.

**CCC (Context Control Center)** - governed, moderated, revisioned, scoped
context (brand voice, terminology, domain knowledge) injected into AI
interactions - overlaps closely enough with the **site memory** scope that
it's worth checking whether CCC should *be* that layer rather than building a
parallel one. Genuine overlap, not just a neighboring concept. Caveat: CCC is
beta as of May 2026 and its own maintainer recommends it for testing/feedback
only - track it, don't build a hard dependency on it yet.

### Comparison: Kenkeep (git-native memory) solves a different problem

Kenkeep is git-native memory for coding assistants - markdown facts about a
*codebase* (conventions, gotchas, decision rationale), with nothing entering
the knowledge base without a human approving it via an ordinary git diff/PR.
No database, no vector search, no runtime write path - updates happen at
review cadence. Fundamentally different volume/latency profile from this
document's design: kenkeep assumes low-volume, high-deliberation,
developer-only contributors reviewed one PR at a time; this design assumes
continuous, high-volume, many-concurrent-non-technical-user ingestion needing
semantic retrieval mid-request. Git's merge model has no answer for hundreds
of live site visitors generating memory-worthy interactions per hour, or
role-gated visibility enforced at request time - it wasn't built for either.

What it does validate: "nothing becomes trusted memory without human
approval" is a real, independently-arrived-at pattern - kenkeep enforces it
via git review, this design enforces the same idea via Content Moderation's
draft-to-trusted state. Worth noting as a sanity check on the design, not a
coincidence. Also worth noting directly: this program's own `annopm` ADR
system is already a kenkeep-shaped pattern - git-native, human-curated,
PR-reviewable institutional memory for the venture itself. Right tool for
that job; not the same job as runtime site memory, which is why it's a
validation of the pattern rather than a competing option here.

### A second product surface: generative/planning use, not just retrospective memory

Everything above treats memory as *about* a site that already exists. The
same extraction/consolidation pipeline, run *before* a site exists, is a
different and arguably stronger pitch: a discovery conversation ("what do you
sell, who's the audience, what content types do you need") consolidates into
memory, and a generation step compiles that memory into a **Drupal Recipe**
(Drupal 10.3+/11's declarative "spec → built site" mechanism, applied via
`drush recipe`) - content types, fields, taxonomy, seed content, scaffolded
directly from the conversation. This isn't hypothetical infrastructure to
build; Recipes already are the "structured spec → buildable site" mechanism
Drupal ships with.

**Pairs naturally with `annotations`:** the same spec that drives the Recipe
can drive Annotation content generation at the same time - documentation of
*why* the structure exists, written from the same source facts, at creation
time rather than backfilled later. Attacks the actual root of the problem
`annotations` was built for (documentation stale from birth) rather than a
downstream symptom.

**Needs its own harness - "spec-kit, but for Drupal":** modeled on GitHub's
Spec Kit (spec.md → plan.md → tasks.md, slash-command driven), a set of
Skills per site archetype (commerce, brochure, LMS, ...) each running a
structured elicitation conversation and writing to the memory-write tool as
it goes. This is real, separate design work - what questions, in what order,
per archetype - sitting on top of the storage/extraction plumbing, not free
just because the plumbing exists.

**Two risks specific to this generative mode, higher stakes than the
retrospective case:**

1. An AI-generated Recipe applied via `drush recipe apply` is a structural
   change to a live site, not a wrong chat answer - a hallucinated or
   malformed recipe can leave a site broken or half-applied. Needs a
   dry-run/human-review gate before apply, not blind trust.
2. If the Recipe's structure and the Annotation content documenting it both
   come from one unreviewed pass over one spec, the result is internally
   consistent, not independently verified - a wrong assumption gets
   documented as confidently as a right one. Still needs a human review pass;
   automation doesn't make the documentation more trustworthy just because
   it was automatic.

### Tool API / MCP - the concrete write path, and it already exists as a pattern

`drupal/tool` (Tool API) defines the callable action as a plugin;
`mcp_server_tool_bridge` auto-exposes Tool API plugins as MCP tools over
`mcp_server`'s endpoint, Bearer-auth secured. This is not new design - it's
the same pattern already mid-build for `annotations_tool`'s own MCP exposure
(ADR-016, PARTIAL). One real gap: `annotations_tool` is read-only today
(`AnnotationStorageService` has no create/save), so a memory-write tool would
be the first *write-capable* Tool API plugin in this ecosystem - follow
`tool_belt`'s write-tool as the precedent rather than annotations_tool's
read-only one. Once built and exposed, connecting an interactive agent
(Claude Code, this session or a fresh one) to it is `claude mcp add` plus a
one-time trust approval - the same shape of connection already active in
this session against `drupal-code-query`'s MCP tools.

## Decision

1. **Proceed as a distinct venture**, not a feature of `annotations` - the
   two share an architectural idea (structured, permission-gated, per-content
   or per-user context) but different products, different buyers.
2. **Storage layer:** entities with `VECTOR` fields via
   `ai_vdb_provider_mariadb`, in-database (default mode, not the
   external-DB option - that breaks Drupal's transaction guarantees and
   isn't worth it here).
3. **Scope model:** four bundles/dimensions - user, role, site, case
   (support tracking) - each with its own retention and visibility rules,
   composable at retrieval the way Mem0 composes `user_id`/`agent_id`/`run_id`.
4. **Governance:** Drupal's existing permission and role system for access
   control; Content Moderation for provenance/audit trail and a
   draft-to-trusted human-review gate on extracted memories, not
   auto-trusted on write.
5. **Processing:** Queue API + a dedicated crontab entry separate from
   `hook_cron`, not a companion server. Revisit only if a specific feature
   (live inline chat extraction) proves the queue-runner cadence
   insufficient.
6. **Sovereignty tier:** local model required for extraction/consolidation,
   configured via `drupal/ai`'s existing Ollama-class provider support.
   Embeddings may use a smaller local model even in the non-sovereign case,
   purely on cost grounds - extraction/reasoning is the step that actually
   forces the sovereignty trade-off.
7. **Build order: PoC before any provider is configured.** Prove the schema
   and consolidation logic with an interactive Claude Code session doing the
   reasoning and a local Ollama container doing embeddings - zero API keys,
   zero recurring cost - before wiring an unattended `drupal/ai` provider for
   autonomous, cron-driven operation.
8. **Every candidate fact runs through Guardrails before it's written.**
   Mandatory, not optional - input/output filtering on extraction output as
   the automated first pass, ahead of the existing draft-to-trusted human
   review gate. Treats every LLM-proposed fact as untrusted input by default.
9. **Generative/planning use (spec → Recipe → built site, paired with
   Annotation content) is in scope as a second product surface**, not just
   retrospective memory about an existing site. Carries its own requirement:
   no `drush recipe apply` without a dry-run/human-review step first - this
   mutates live site structure, a different and higher-stakes risk than
   writing a memory fact.

## Consequences

- This is a genuine build, not a config exercise - the schema (bundle per
  scope, provenance references, validity windows) and the
  extraction/consolidation/decay plugin system are the real work; the
  storage and async-processing primitives are already available.
- Role memory is the one category worth watching as a differentiator -
  nothing surveyed (Mem0, Zep) offers it natively, because neither framework
  has anything resembling Drupal's role system underneath it.
- Sovereign deployments carry a real infrastructure cost (local model
  hosting for extraction/consolidation) that non-sovereign, SaaS-embedding
  deployments don't - this needs to be priced and sized before it's pitched
  as a feature, not assumed away.
- Depending on `ai_vdb_provider_mariadb` ties the storage layer to a
  single-maintainer module with light adoption (~103 sites). Low risk given
  it sits behind `drupal/ai`'s provider abstraction (swappable for
  Postgres/pgvector later without touching the schema design), but not
  zero.
- Memory poisoning is a named, real risk once this design is committed to,
  not a hypothetical - persistent memory makes a successful injection worse
  than ordinary prompt injection because it doesn't expire with the
  conversation. Guardrails plus the human-review gate are the mitigation;
  neither is optional once there's an extraction pipeline writing untrusted
  content into a trusted store.
- If open source is the framing, success criteria change: broad
  composability across many independent use cases matters more than one
  buyer's ROI case. Doesn't resolve open question 1 below, but changes what
  "viable" means enough to revisit it rather than treat it as answered.
- The generative/planning surface, if built, adds a config-mutation risk
  category (a bad Recipe can break a live site) on top of the
  content-mutation risk category (a bad memory fact) the rest of this
  document already covers - needs its own review discipline, not an
  extension of the existing one.

## Open questions

1. **What's the first concrete feature this unlocks**, sellable on its own -
   raised in an earlier conversation and still unanswered. "Generic agent
   memory infrastructure" isn't a pitch; a specific scope (e.g. support
   tracking, or role memory for an editorial team) might be.
2. **Consolidation conflict-resolution policy** - not designed when this was
   written. **Answered:** see [0005](0005-consolidation-algorithm.md).
3. **Local model choice and hardware sizing for the sovereign tier** - not
   researched. Needs a concrete recommendation (model family, minimum
   GPU/CPU spec) before "sovereign" is something that can be quoted to a
   customer.
4. **Queue-runner cadence** - "every 1-5 minutes" above is a safe default,
   not a tuned answer; real cadence depends on interaction volume and
   whichever model/API is doing extraction, unmeasured so far.
5. **Retrieval latency at realistic scale** - asserted safe above by
   reasoning about SQL query cost, not benchmarked against an actual graph
   of meaningful size. **Partially answered 2026-09-10:** a benchmark
   harness now exists (`drush aim:benchmark`, `aim`'s own repo - see its
   CLAUDE.md "Benchmarking" section), and a first small-scale run (5 then 15
   site-scope facts) measured `recall()` averaging 450-540ms - well above
   this document's "single-digit-to-low-double-digit ms" assertion, but that
   assertion was reasoning about the SQL/HNSW layer alone. The real cause:
   `recall()` has to embed the query text via the hosted embeddings provider
   before it can search at all, and that network round trip, not the SQL
   query, is what the measurement actually caught. Still open: whether
   recall time grows with corpus size (only tested at trivial scale so far),
   and whether a local (Ollama) embedding model removes the bottleneck as
   the mechanism above suggests it should - not yet tried.
6. **Relationship to `ai_agents`/`ai_search`** - build this as a module that
   composes with them (they do tool-calling/indexing, this does memory), or
   fully standalone? **Answered for the chatbot surface, 2026-09-10 (`aim`'s
   CLAUDE.md):** composes. The demo chatbot migrated off `ai_assistant_api`'s
   deprecated Action mechanism onto `ai_agents`' FunctionCall tools, split
   into a new optional `aim_chatbot` submodule that depends on `ai_agents`;
   `aim` core itself still has zero dependency on it. Same session also
   checked CCC's (`ai_context`) actual shipped RAG guidance against this
   question - see CLAUDE.md's "CCC crossover" section - and confirmed the
   right integration shape is aim exposing tools for an agent to call
   (`ai_agents`-shaped), not aim becoming a CCC content source.
7. **Product framing** - own module, own venture pitch, or a capability
   folded into an existing pitch (LGD, Canvas)? Explicitly out of scope for
   this document per the opening context - this one is architecture only.
8. **Speckit-for-Drupal's actual question sets** - no design work done on
   what a spec-gathering Skill asks, in what order, per site archetype
   (commerce, brochure, LMS, ...). Real scope of work, not a byproduct of the
   storage/extraction plumbing.
9. **Recipe-apply safety gate** - "needs a dry-run/review step before
   applying an AI-generated Recipe" is stated as a requirement above. Design
   landed: see [0009](0009-recipe-apply-safety-gate.md) (design only, not
   yet built).
10. **CCC: adopt, integrate, or build a parallel site-memory layer?** - CCC's
    beta status means this can't be answered yet; revisit once it reaches a
    stable release, rather than defaulting to building a competing mechanism
    now. **Status check 2026-09-10 (`aim`'s CLAUDE.md):** still beta
    (`1.0.0-beta5`, released 2026-09-06, machine name `ai_context`) - not
    stable yet, so the "don't build a hard dependency on it" stance still
    holds. Worth noting: real install growth since May (296 sites on the
    1.0.x branch) and it's now a dependency of `ai_agents_experimental_
    collection` and `ai_agents_ossa` - it's integrating into the `ai_agents`
    ecosystem, not just standing alone. Re-check on its next stable release.
11. **PoC build order confirmed but not started** - the zero-API-key path
    (Claude Code for reasoning, local Ollama for embeddings) is the agreed
    starting point. **Answered:** built - see CLAUDE.md's project snapshot,
    "working PoC - real `aim_fact` entity, real vector search, real
    embeddings."
