# AIM memory workflow diagrams

How a fact moves through the module, as the code stands on 2026-10-02
(`AimMemoryManager`, `AimConsolidateQueueWorker`, `AimCommands`,
`AimGuardrailsConstraint`, `aim_tool`, `aim_chatbot`). Not a decision
record: for the reasoning see [0000-index.md](0000-index.md).

Five diagrams: write path (1), consolidation (2), recall (3), and
call counts for a sample round (4), and exact values (5). The
write path ends by enqueuing a fact for consolidation, which is the
hand-off into diagram 2. Diagram 3 reads what diagrams 1 and 2 left in
the vector table.

Color key, used in all three:

- Blue, "LLM": a chat or decision model is called (can be remote).
- Orange, "Vector": embedding or vector math (an embeddings model call
  at index/query time, or MariaDB cosine distance in SQL).
- Gray, "Code": plain PHP, config or database logic, no model.
- Red: a rejection or dead end.

## 1. Write path: input to saved, queued and indexed fact

```mermaid
flowchart TD
  classDef llm fill:#cfe3ff,stroke:#2b6cb0,color:#102a43
  classDef vec fill:#ffe2c2,stroke:#c05621,color:#3d1f00
  classDef code fill:#eceff1,stroke:#607d8b,color:#1f2a30
  classDef bad fill:#fdd,stroke:#c53030,color:#4a0000

  subgraph IN["Write paths in"]
    A1["drush aim:remember<br/>(single, or --file batch)<br/>used by agents such as Claude Code<br/>via the aim-memory skill"]
    A2["MCP / Tool API aim_remember<br/>needs 'store aim memory' plus<br/>per-scope 'create {scope} aim facts'<br/>source default tool:aim_remember"]
    A3["Chatbot FunctionCall AimRemember<br/>scope forced to site<br/>source chatbot:aim_chatbot"]
    A4["drush aim:extract file<br/>aim-discovery skill runs this<br/>(not aim:remember)"]
    A5["Ingest form, mode 1: extract (planned)<br/>upload prose (.txt), needs<br/>'ingest aim memory'<br/>same path as aim:extract"]
    A6["Ingest form, mode 2: one fact per line (planned)<br/>no model call, scope and subject<br/>chosen once on the form<br/>each line goes to remember()"]
  end

  A1 --> R["AimMemoryManager::remember()"]
  A2 --> R
  A3 --> R

  A5 --> X
  A6 --> R
  A4 --> X["extractFacts()<br/>structured-output chat call<br/>returns scope, subject, text list"]:::llm
  X --> C["createFactsFromCandidates()<br/>per candidate, one bad one<br/>does not abort the batch"]:::code
  C -->|"scope=user and no<br/>--subject-uid"| SK["skipped, counted"]:::bad
  C --> V

  R --> R1{"scope is an<br/>installed aim_scope?"}:::code
  R1 -->|no| ERR["InvalidArgumentException<br/>returned to caller"]:::bad
  R1 -->|yes| R2{"scope requires a<br/>real account?<br/>user scope, ADR-0007"}:::code
  R2 -->|"yes: subject must be a<br/>uid of a real user"| R3["resolve account<br/>no match: exception"]:::code
  R2 -->|"no, subject empty"| R4["scope plugin defaultSubject()<br/>e.g. case mints a case ID"]:::code
  R2 -->|"no, subject given"| R5["subject stored as given"]:::code
  R3 --> R6["build entity: text, source,<br/>optional state, category terms,<br/>asserted, target_type/target_id,<br/>optional explicit trusted"]:::code
  R4 --> R6
  R5 --> R6
  R6 --> V

  V["saveFact(): $entity->validate()"]:::code --> G["AimGuardrails constraint on text<br/>runGuardrails(): set aim_write_guardrails"]:::code
  G --> G1{"aggregated stop score<br/>reaches threshold 1.0?"}:::code
  G1 -->|"yes: aim_max_length 2000 chars,<br/>or aim_no_markup regex for HTML tags"| REJ["violation, InvalidArgumentException<br/>nothing saved<br/>warning logged without text<br/>remember: error to caller<br/>extract: counted as blocked"]:::bad
  G1 -->|no| SAVE["entity save()<br/>trusted = explicit value if passed,<br/>else aim.settings default_trusted<br/>(module default false, this site true)"]:::code

  SAVE --> H["hook_aim_fact_insert<br/>skipped when entity is syncing"]:::code
  H --> Q["enqueue fact ID on queue aim_consolidate<br/>see diagram 2"]:::code
  SAVE --> T["Search API tracker marks item<br/>for (re)index"]:::code
  T --> IDX{"index_directly?<br/>shipped: off, this site: on"}:::code
  IDX -->|on| EMB
  IDX -->|"off: cron_limit 50<br/>or drush sapi-i, or the<br/>queue worker's reindex()"| EMB
  EMB["aim_exclude_retired processor<br/>drops facts with expires set<br/>(plain code)"]:::code --> EMB2["embed field text only<br/>(main_content) via the<br/>server's embeddings engine"]:::vec
  EMB2 --> VT["row in aim_fact_vectors<br/>MariaDB VECTOR column, HNSW index<br/>M=16, ef_search=100<br/>attributes stored beside the vector:<br/>scope, subject, source, user, trusted"]:::vec
```

Notes on diagram 1:

- The ingest form (planned, ADR-0016 Mode 1) has two plain-text modes:
  prose goes through extraction (one frontier call per file, the model
  picks scope and subject); one fact per line skips the model entirely
  (scope and subject set once on the form, deterministic, free). A JSON
  file in the `aim:remember --file` shape stays as an advanced option for
  case IDs and entity targets, which extraction cannot set.
- Indexed fields: `text` is the embedded content. `scope`, `subject`,
  `source`, `user` and `trusted` are stored as attributes. The filterable
  ones used by code are `scope`, `subject`, `user` (BTREE index) and
  `trusted` (BTREE index). `source` is stored but no code filters on it.
  `expires` is not indexed: retired facts are removed by the processor
  instead.
- Guardrails here are deterministic (regexp plus length). The set has no
  LLM-backed guardrail, so the gate makes no model call.
- A rewriting guardrail would replace the text before save; none ships.

## 2. Consolidation: queue to retired or merged facts

```mermaid
flowchart TD
  classDef llm fill:#cfe3ff,stroke:#2b6cb0,color:#102a43
  classDef vec fill:#ffe2c2,stroke:#c05621,color:#3d1f00
  classDef code fill:#eceff1,stroke:#607d8b,color:#1f2a30
  classDef bad fill:#fdd,stroke:#c53030,color:#4a0000

  QI["queue aim_consolidate<br/>item = fact ID<br/>drained by dedicated crontab:<br/>drush queue:run aim_consolidate<br/>(no cron key, not hook_cron)"]:::code
  QI --> W1{"fact still exists<br/>and expires empty?"}:::code
  W1 -->|no| DONE0["return, nothing to do"]:::bad
  W1 -->|yes| W2["reindex() so the fact and<br/>its neighbors are searchable"]:::vec
  W2 --> W3{"default chat provider<br/>configured?"}:::code
  W3 -->|no| SUSP["SuspendQueueException<br/>queue stops, item released"]:::bad
  W3 -->|yes| N

  MAN["drush aim:consolidate<br/>sweeps all unexpired facts by id,<br/>--dry-run prints decisions only"]:::code --> N

  N["findNeighbors(), up to 3<br/>embed this fact's text as a query<br/>(query-embedding cache applies)<br/>filter scope, plus user or subject<br/>skip self, retired, expired;<br/>each neighbor decided in turn, stop if this fact retires"]:::vec
  N --> N1{"neighbor found?"}:::code
  N1 -->|no| DONE1["done, no change"]:::code
  N1 -->|yes| S{"cosine distance<br/>score"}:::vec
  S -->|"above 0.45<br/>ambiguous_threshold"| SKIP["unrelated: skip, zero cost"]:::code
  S -->|"at or below 0.09<br/>auto_threshold"| AUTO["decision NOOP, no model call<br/>auto path keeps the older fact"]:::vec
  S -->|"0.09 to 0.45<br/>ambiguous band"| CL["classifyPair()<br/>kept = lower ID, candidate = higher ID<br/>one structured call, or a decision-backend<br/>choice if activities.consolidation uses it"]:::llm

  CL --> D{"model decision"}:::llm
  D -->|ADD| ADD["both stand, no change"]:::code
  D -->|NOOP| NOOP["decision NOOP"]:::code
  D -->|DELETE| DEL["decision DELETE"]:::code
  D -->|UPDATE| MT["merged text<br/>from the same reply, or from a<br/>separate merge-writer chat call<br/>when a decision backend judged<br/>(none configured: downgrade to ADD)"]:::llm

  MT --> MG["runGuardrails(merged text)"]:::code
  MG -->|rejected| BLK["BLOCKED: both facts untouched<br/>warning logged"]:::bad
  MG -->|passed| VM["verifyMerge()"]:::code
  VM --> VM1{"merge_max_distance > 0?<br/>shipped 0 = off"}:::code
  VM1 -->|"on: embed merged text and<br/>both inputs, cosine distance<br/>over the max fails"| FAIL
  VM1 --> VM2{"merge_verify on?<br/>shipped: on"}:::code
  VM2 -->|"yes: verifier model<br/>(activities.verifier, else same model)<br/>asked: faithful?"| VM3["faithful true or false"]:::llm
  VM3 -->|false or error| FAIL["merge unfaithful:<br/>downgrade UPDATE to ADD<br/>both facts stand, audit line"]:::bad
  VM3 -->|true| UPD["UPDATE accepted"]:::code
  VM2 -->|no| UPD

  NOOP --> RN
  AUTO --> RN
  DEL --> RD
  UPD --> RU

  RN["retire candidate:<br/>expires = now<br/>superseded_by = kept fact<br/>superseded_by_reason = JSON"]:::code
  RD["soft retire candidate:<br/>expires = now<br/>superseded_by stays empty<br/>superseded_by_reason = JSON"]:::code
  RU["createMergedFact(): new fact via saveFact<br/>owner and identity from kept fact,<br/>asserted from candidate only,<br/>state/source candidate else kept,<br/>categories unioned,<br/>trusted only if both inputs trusted<br/>then retire BOTH inputs:<br/>expires = now, superseded_by = merged fact<br/>reason JSON stored on candidate"]:::code

  RN --> IX["retired fact re-tracked: next index run<br/>removes it from aim_fact_vectors<br/>(aim_exclude_retired)"]:::vec
  RD --> IX
  RU --> IX
  RU --> NEWQ["merged fact insert enqueues<br/>itself on aim_consolidate"]:::code
  NEWQ -.-> QI

  JR["superseded_by_reason JSON:<br/>decision, by auto or model, score,<br/>provider, model ID<br/>never fact text"]:::code
  RN -.-> JR
  RD -.-> JR
  RU -.-> JR
```

Notes on diagram 2:

- Thresholds are cosine distances, lower is closer: "at or below"
  means more similar. Defaults 0.09 and 0.45 live in
  `AimMemoryManager::DEFAULT_*`; the live values come from
  `aim.settings` (`auto_threshold`, `ambiguous_threshold`).
- Retirement is always soft. Nothing is deleted from `aim_fact`, and a
  retired fact can be un-retired by the admin action.
- `ADD` and `BLOCKED` change nothing, and the same pair is simply
  evaluated again on a later sweep.
- The queue and the sweep both call the same `decideAndApply()`.

## 3. Recall: query to returned facts

```mermaid
flowchart TD
  classDef llm fill:#cfe3ff,stroke:#2b6cb0,color:#102a43
  classDef vec fill:#ffe2c2,stroke:#c05621,color:#3d1f00
  classDef code fill:#eceff1,stroke:#607d8b,color:#1f2a30
  classDef bad fill:#fdd,stroke:#c53030,color:#4a0000

  C1["drush aim:recall<br/>max distance only if --max-distance given<br/>(none by default)"]:::code
  C2["MCP / Tool API aim_recall<br/>needs 'read aim memory'<br/>scope=user with no subject_uid:<br/>defaults to the calling account<br/>max distance default = recall_max_distance"]:::code
  C3["Chatbot FunctionCall AimRecall<br/>scope forced to site, limit 5<br/>max distance = recall_max_distance"]:::code

  C1 --> RC
  C2 --> RC
  C3 --> RC

  RC["AimMemoryManager::recall()"]:::code --> I{"aim_vector_index exists?"}:::code
  I -->|no| E1["RuntimeException"]:::bad
  I -->|yes| U{"subject-uid given and<br/>resolves to a user?"}:::code
  U -->|"given, no such user"| E2["InvalidArgumentException"]:::bad
  U -->|"ok or not given"| F["build query with keys = query text<br/>conditions: scope, subject, user,<br/>trusted = TRUE unless include-untrusted<br/>range: limit, or limit x 5 when user-filtered"]:::code

  F --> EX["executeSearchQuery()<br/>anonymous caller (drush/cron):<br/>search_api_bypass_access on"]:::code
  EX --> EC{"query embedding cached?<br/>key: provider, model, config, text<br/>TTL 7 days, query time only"}:::vec
  EC -->|miss| EM["embed query text<br/>embeddings model call"]:::vec
  EC -->|hit| KNN
  EM --> KNN["MariaDB VEC_DISTANCE_COSINE over<br/>aim_fact_vectors, HNSW, pre-filtered<br/>by attribute conditions<br/>rows come back nearest first"]:::vec

  KNN --> PR["per result row"]:::code
  PR --> P1{"real authenticated caller?<br/>ai_search checks entity view access<br/>on each result: per-scope<br/>'view {scope} aim facts', user-scope<br/>role matrix, entity-scope plugin"}:::code
  P1 -->|"no access"| DROP["result dropped"]:::bad
  P1 -->|"allowed, or bypassed<br/>for drush/cron"| P2{"distance above<br/>max distance?"}:::vec
  P2 -->|yes| DROP
  P2 -->|no| P3{"entity still exists?<br/>(stale index row)"}:::code
  P3 -->|no| DROP
  P3 -->|yes| P4{"expires set?<br/>retired since last index run"}:::code
  P4 -->|yes| DROP
  P4 -->|no| OUT["row: id, score (distance),<br/>scope, subject, text, source,<br/>state, trusted"]:::code
  OUT --> LIM{"limit reached?"}:::code
  LIM -->|yes| RET["return rows"]:::code
  LIM -->|no| PR
```

Notes on diagram 3:

- Recall makes no chat-model call. The only model involved is the
  embeddings model, and only on a cache miss.
- Score is a distance: 0 is identical. The default cutoff is 0.45 in
  code, 0.48 on this site (ADR-0019 addendum).
- `recall()` itself has no explicit access call: per-result access is
  enforced inside Search API's ai_search backend, and is bypassed for an
  anonymous (drush/cron) caller by design.
- Untrusted facts are excluded by the `trusted = TRUE` condition unless
  `--include-untrusted` is passed (no Tool API or chatbot path passes it).

## Legend (plain English)

- **Write path.** Everything funnels into one of two PHP methods.
  `remember()` stores exactly what a caller hands it (CLI, MCP tool,
  chatbot tool); no model decides what is worth keeping. `aim:extract`
  is the only path where a model reads source text and proposes facts,
  and those candidates then go through the same validation and save
  step. The aim-discovery skill ("the grill") is not a separate path: it
  interviews, writes a notes file, and runs `aim:extract` on it.
- **Guardrails.** A fact is validated as an entity field constraint on
  `text` before it is saved. The shipped rules are a 2000 character cap
  and a no-HTML-tags pattern. A failure saves nothing; a single-fact
  caller gets an error, an extraction batch just counts it as blocked and
  carries on.
- **Trusted.** A new fact takes `aim.settings:default_trusted` unless
  the caller passes an explicit value (only curated seed data does).
  Recall hides untrusted facts by default.
- **Indexing.** Saving a fact marks it for indexing. Its text is turned
  into an embedding and stored with a few filterable attributes in
  `aim_fact_vectors`. Retired facts are removed from that table.
- **Consolidation.** Runs later, off the request path, one queue item per
  new fact. Same-scope nearest neighbor by cosine distance: very close
  is retired without a model, moderately close asks a model to choose
  ADD (keep both), NOOP (restatement), DELETE (should not exist) or
  UPDATE (merge into a new fact). A merge must pass the guardrails and a
  faithfulness check or it falls back to keeping both.
- **Retire.** Setting `expires` (and where there is a replacement,
  `superseded_by`) hides a fact from recall and the index without
  deleting it. `superseded_by_reason` records how and by what it was
  decided, with no fact text.
- **Recall.** Embed the query (cached), find nearest vectors with
  attribute filters, then drop anything the caller may not see, is too
  far away, is retired or has gone missing.

## 4. Calls per round: 100 new facts and 20 direct queries

Estimates from the code and one dry run (63 facts gave 94 consolidation
decisions, about 1.5 per fact), not a measurement. "Frontier" is the
frontier chat model (the site default chat model); "Jev" is whichever
decision backend `activities.consolidation` and `activities.verifier`
use; "embed" is the local embeddings model.

```mermaid
flowchart LR
  classDef llm fill:#cfe3ff,stroke:#2b6cb0,color:#102a43
  classDef vec fill:#ffe2c2,stroke:#c05621,color:#3d1f00
  classDef code fill:#eceff1,stroke:#607d8b,color:#1f2a30

  IN100["100 new facts"]:::code
  Q20["20 direct queries"]:::code

  IN100 -->|"from text (aim:extract)"| EX["Frontier: 1 call per text blob<br/>whole file in one prompt, no chunking"]:::llm
  IN100 -->|"directly: remember, JSON,<br/>or the form's one-fact-per-line mode"| NOEX["no extraction call"]:::code
  EX --> EMB1["embed: 100"]:::vec
  NOEX --> EMB1
  EMB1 --> CONS["consolidation: up to 3 neighbors each<br/>only pairs between 0.09 and 0.45 distance"]:::code
  CONS --> JEV["Jev decision: about 150 to 300 calls<br/>0.4 s each, tiny inputs"]:::llm
  JEV -->|"UPDATE, rare"| MERGE["Frontier merge writer: 1 per UPDATE<br/>then Jev verify: 1, embed: 1"]:::llm

  Q20 -->|"aim:recall, MCP, Tool API"| QR["embed: 20, no LLM"]:::vec
  Q20 -->|"through the chat assistant"| QC["embed: 20<br/>Frontier: about 2 per turn, about 40"]:::llm
```

Notes on diagram 4:

- **Extraction is one call per input, not per fact.** `aim:extract` reads
  the whole file into one prompt (no chunking), so a file larger than the
  frontier model's context fails; split big files first.
- **Jev limits** (secondhand, from TypeSafe's public docs summary, not
  verified against the live API): 64k context, 32k tokens for the state
  plus the longest question; 40 requests/s and 100k tokens/s. A pair of
  facts is about 100 tokens (a fact is capped at 2000 characters by the
  write guardrail, about 500 tokens), far inside every limit.
- Cost: Jev is $0.042 per million input tokens, so 300 calls are well
  under a cent; the amazeeio frontier model is free.


## 5. Exact values: how a literal reaches an answer

Memory recall and exact values are separate. Recall finds facts; the
assistant calls a lookup tool when it needs an exact value. The value
always comes from the `literals` store, read as the person asking.

```mermaid
flowchart TD
  classDef llm fill:#cfe3ff,stroke:#2b6cb0,color:#102a43
  classDef vec fill:#ffe2c2,stroke:#c05621,color:#3d1f00
  classDef code fill:#eceff1,stroke:#607d8b,color:#1f2a30
  classDef bad fill:#fdd,stroke:#c53030,color:#4a0000

  Q["Visitor question"]:::code
  A["Chat model (the gate)<br/>decides what it needs"]:::llm

  subgraph MEM["Memory: aim_recall"]
    R1["Embed the question,<br/>nearest facts by vector distance"]:::vec
    R2["recall_gap trims the loose tail"]:::code
    R3["replaceTokens() per fact,<br/>as the recalling account"]:::code
    R4{"Every token<br/>readable?"}:::code
    R5["Fact text, value in place"]:::code
    R6["Fact withheld whole<br/>(debug: [redacted])"]:::bad
  end

  subgraph LIT["Exact value: literals:lookup tool"]
    L1["By key, search words,<br/>or question (finder, optional)"]:::code
    L2["Resolve the value<br/>access checked for the viewer"]:::code
    L3["Label and exact value<br/>(no value if not allowed)"]:::code
  end

  Q --> A
  A -->|"needs facts"| R1 --> R2 --> R3 --> R4
  R4 -->|yes| R5 --> A
  R4 -->|no| R6
  A -->|"needs a phone number,<br/>a URL"| L1 --> L2 --> L3 --> A
  A --> Z["Reply, value given exactly as returned"]:::llm
```

Read it this way:

- **The model is the gate.** It decides whether a question is a one-shot
  value lookup, so nothing calls a decision model on every recall.
- **Nothing is copied.** The value lives in the literal; a fact holds at most
  a token, so editing the literal changes every answer with no reindex.
- **Who sees what is decided at read time**, by the person asking.
- **The finder is optional.** It only maps a loose question to a literal for
  the tool's `question` mode; key and search-word lookups need no model.
