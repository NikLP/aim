# ADR-0067: A fact approved on a Site Agent card is the person's own words

**Status:** Accepted, built 2026-11-15 as a stopgap. The Site Agent change
is a local Composer patch on this site; aim's side is built; the upstream
issue is not filed.
**Date:** 2026-11-15

## In plain terms

Before: when staff approve a fact on Site Agent's approval card, aim can't
tell a person approved it. It runs the grounded check anyway, the chat model's
small rewording ("I'm" to "Sam", an added year) scores low, and the fact
the person just approved goes back to review.

After: Site Agent marks a call a person approved (or ran themselves), and aim
treats that fact as the person's own words, the way it already treats text
typed on the add form, the ingest form or Drush: no grounded check.

Gains: approved facts land trusted when the writer may trust them, with one
human check instead of two. Costs: a small change to Site Agent (upstream,
carried here as a patch until then) and a few lines in `aim_site_agent` and
`aim_remember`.

## Context

- **The card.** `aim_site_agent` classifies `aim_remember` as
  `content_write`, and the Memory toolset sets it to `always`, so each save
  shows a card listing every fact, its scope, subject and `expires`, and the
  `source_text` it came from. Site Agent binds the decision to a hash of the
  payload and runs the stored payload, so what runs is what was shown.
- **The grounded check** ([ADR-0037](resolved/0037-transient-source-passages-for-grounding.md))
  asks a decision model whether a fact is stated in its `source_text`. In
  `demote` mode ([ADR-0065](resolved/0065-permissions-are-trust.md)) a low
  score saves the fact untrusted. It exists to catch a model misreporting
  what a person said; text a person writes has no source text and is not
  checked.
- **What happened** (live test, 2026-11-14): facts 33 and 34, written through
  Site Agent by uid 1 after approval, scored 0.02 and 0.20 and saved
  untrusted. The model had written "Sam is allergic to peanuts" from "I'm
  allergic to peanuts" and added the year to "closed until 20 November". The
  person had already approved that exact wording.
- **Keying on the caller is wrong.** Site Agent does not always ask: a
  toolset can set `aim_remember` to `pre_approved`, and in `once` mode only
  the first call in a thread shows a card. Skipping the check for every
  `site_agent:aim_remember` write would skip it for writes nobody read.
- **Reading Site Agent's records works but reaches into its internals.**
  The running `site_agent_tool_call` record carries `decided_by` (set on an
  approved or owner-run call, empty on a pre-approved one), but matching it
  to the running tool means querying by status, owner and arguments.
- **Tool API already has the slot.** `Invoker` takes open-ended
  capabilities: `new Invoker(string $id, \UnitEnum ...$capabilities)`, and
  `InvokerCapability`'s docblock says "Other modules define their own enums
  for behaviors they subscribe to; Invoker::has() accepts any enum case."
  Site Agent builds a fresh invoker for every call
  (`SiteAgentInvoker::create()` in `ToolCallExecutor::prepare()`), including
  the re-prepare just before an approved call runs (`CallHandler::runApproved()`).

## Decision

1. **A call a person approved word for word is written by that person.**
   aim does not run the grounded check on it. Trust still comes from the
   writer's permissions alone (ADR-0065): an approver without the trust
   permission still lands in review; the approval only stops the model from
   withholding trust. The time check
   ([ADR-0057](0057-dates-resolved-and-confirmed-before-write.md)), which
   asks for missing data rather than doubting the text, and Guardrails still
   run.
2. **Site Agent says so on the invoker.** It adds
   `SiteAgentInvokerCapability::PersonApproved` when it runs a call after a
   person approved its card, or a call the thread's owner ran themselves. It
   does not add it to a pre-approved call, including a `once` call after the
   first approval in a thread. The full change is in the appendix.
3. **aim reads it, without depending on Site Agent** (built 2026-11-15).
   `AimRemember` names the case as a string,
   `AimRemember::PERSON_APPROVED`, the way `CALLERS` names the `site_agent`
   invoker ID, and checks it with `defined()`/`constant()` and
   `Invoker::has()` on the invoker it already gets. When it is set,
   `source_text` is left out of the grounded check for that call, and an
   audit line says so. Without the patch the constant is undefined and
   nothing changes. The card still shows `source_text`: it reads the stored
   arguments, not what the tool did with them. If the case moves into Tool
   API's own `InvokerCapability` (see below), the string becomes a plain
   reference. Test: `aim_tool`'s `AimRememberApprovalTest` (skipped where
   the case does not exist).
4. **A conversational "shall I save this?" does not count.** The visitor
   chat's confirmation turn is the model's word, not something aim can
   verify, so chat writes keep the grounded check.
5. **Until Site Agent ships it**, this site carries the change as a Composer
   patch (below).

### Not chosen

- Skip the check by invoker ID (`site_agent`): wrong for `pre_approved` and
  `once` (Context).
- A per-channel grounding setting: more configuration for one case, and
  still keyed on the channel, not on whether a person looked.
- Look up the running `site_agent_tool_call` record and its `decided_by`:
  works today with no upstream change, but couples `aim_site_agent` to Site
  Agent's record statuses and argument storage.
- Set `grounding_mode` to `record` site-wide: also stops demoting MCP and
  extraction writes, where the check catches real drift.

## Relation to tool#3583125

[tool#3583125](https://git.drupalcode.org/project/tool/-/work_items/3583125)
(filed 2026-10-28, no response yet) asks Tool API to hand a `#[ToolMethod]`
its invoker. That is delivery; this record is about what the invoker says.
Neither depends on the other: `AimRememberInvokerSubscriber` already delivers
the invoker, and the approval rides on it. #3583125 landing would only let
the subscribers go.

## Upstream issue (Site Agent), ready to file

**Title:** Tell a tool when a person approved its call

**Problem.** When a call runs after someone approves its card, or the thread
owner runs it themselves, the tool can't tell that apart from a pre-approved
call: both get the same `SiteAgentInvoker`. Tools that treat a person's
approved input differently from a model's need to know.

**Our case.** aim's `aim_remember` runs a model check that a fact's text is
supported by the words it came from, and holds a low scorer for review. On a
Site Agent card the person has already approved the exact text (bound by the
payload hash), so the check is redundant, and a model's harmless rewording
sends approved facts back to review.

**Proposal.** Add an invoker capability, `SiteAgentInvokerCapability::PersonApproved`,
set when the call runs after approval (`CallHandler::runApproved()`) or as
the owner's own call (`CallHandler::runAsOwner()`), not on a pre-approved
call, including a "once" call after the first approval. Tool API's
`Invoker` already accepts any module's enum cases, so this needs no Tool API
change. `CallHandler::prepare()` and `ToolCallExecutor::prepare()` gain a
trailing `bool $person_approved = FALSE`, passed to
`SiteAgentInvoker::create()`. The payload hash is unchanged (it covers tool
ID and arguments only). Dry runs and the tool list builder keep the plain
invoker.

**Alternative home.** If it fits better generically, the case could live in
Tool API's `InvokerCapability` (one line), so any approval flow can set it
and a tool can read it without depending on Site Agent. Happy to file that
side too.

**Tests.** A kernel test, `PersonApprovedInvokerTest`: pre-approved (absent),
approved (present), "once" (present on the approved call, absent on the
next), owner-run (present). The `site_agent_test:write_note` fixture records
`$this->invoker?->has(...)` in state.

**Side note for the maintainer.** With Tool API newer than beta11 (we run
`1.0.x-dev`), a `Write` tool that doesn't declare `destructive` counts as
destructive, so `site_agent_test:write_note` always prompts. Site Agent's
own `ApprovalContractTest::testPreApprovedCallRuns` fails there, and so do
the pre-approved and "once" cases above; with `destructive: FALSE` on the
fixture all four pass. Against the pinned beta11 (`require-dev`) they should
pass as they are. Declaring `destructive: FALSE` on the note fixtures would
keep them working past beta11.

The patch applies cleanly to 1.0.3 and to `1.0.x` at `4bff0c7` (checked
2026-11-15).

## Local patch on this site

`patches/site_agent-person-approved-invoker.patch` in the site root (not
this repo), listed under `extra.patches` for `drupal/site_agent` in the
site's `composer.json`, `patches.lock.json` relocked. Applied to 1.0.3.
phpcs (Drupal, DrupalPractice) is clean on the changed files. Remove it
when a Site Agent release ships the change; if upstream picks a different
name or home for the case, follow theirs in step 3.

## Consequences

- Staff approval on a card becomes the human check for that write; the
  review queue keeps facts nobody read word for word (extraction, ingest of
  unreviewed text, the visitor chat, MCP).
- `AimRemember` gains one constant and one private method; no setting, no
  subscriber, no new public API.
- When upstream ships it (or names it differently), update
  `AimRemember::PERSON_APPROVED` and drop the local patch.

## Appendix: the Site Agent patch

Site Agent's own wording and style, so spell-checking is off for it.

<!-- cspell:disable -->
```diff
diff --git a/src/Tool/CallHandler.php b/src/Tool/CallHandler.php
index 0be24b4..8480199 100644
--- a/src/Tool/CallHandler.php
+++ b/src/Tool/CallHandler.php
@@ -116,7 +116,7 @@ final class CallHandler {
       throw new \LogicException('Only the thread\'s owner runs calls in it themself.');
     }
     $call_id = self::OWNER_CALL . bin2hex(random_bytes(8));
-    $prepared = $this->prepare($thread, $offered, $name, $arguments);
+    $prepared = $this->prepare($thread, $offered, $name, $arguments, TRUE);
     if (!$prepared->isReady() || $prepared->tool === NULL) {
       $result = $this->executor->refusal($prepared);
       $record = $this->recorder->record($thread, $call_id, $name, $prepared, $arguments, ToolCallInterface::STATUS_FAILED, $result->outcome, NULL, $this->currentUser, $result->summary);
@@ -178,7 +178,7 @@ final class CallHandler {
     }
     $thread = $fresh;
     $offered = $this->toolListBuilder->build($this->toolsetAccess->usableToolsets($this->currentUser), $this->currentUser);
-    $prepared = $this->prepare($thread, $offered, $name, $arguments);
+    $prepared = $this->prepare($thread, $offered, $name, $arguments, TRUE);
     if (!$prepared->isReady()) {
       $result = $this->executor->refusal($prepared);
     }
@@ -207,10 +207,13 @@ final class CallHandler {
    *   The function name the model called.
    * @param array<string, mixed> $arguments
    *   The model's arguments.
+   * @param bool $person_approved
+   *   Whether a person decided this exact call: approved its card, or ran it
+   *   as the thread's owner. The tool is told through its invoker.
    */
-  public function prepare(ThreadInterface $thread, OfferedTools $offered, string $name, array $arguments): PreparedCall {
+  public function prepare(ThreadInterface $thread, OfferedTools $offered, string $name, array $arguments, bool $person_approved = FALSE): PreparedCall {
     $config = $offered->get($name)?->classification->isConfigWrite() ?? FALSE;
-    $prepared = $this->workspaceScope->run($thread, $config, fn (): PreparedCall => $this->executor->prepare($offered, $name, $arguments, $this->currentUser, $this->threadReference->forThread($thread)));
+    $prepared = $this->workspaceScope->run($thread, $config, fn (): PreparedCall => $this->executor->prepare($offered, $name, $arguments, $this->currentUser, $this->threadReference->forThread($thread), $person_approved));
     assert($prepared instanceof PreparedCall);
     $writes_content = [ToolKind::ContentWrite, ToolKind::Code];
     if ($prepared->isReady() && in_array($prepared->tool?->classification->kind, $writes_content, TRUE)) {
diff --git a/src/Tool/SiteAgentInvoker.php b/src/Tool/SiteAgentInvoker.php
index 60ca526..ed620e7 100644
--- a/src/Tool/SiteAgentInvoker.php
+++ b/src/Tool/SiteAgentInvoker.php
@@ -22,9 +22,15 @@ final class SiteAgentInvoker {
    *
    * Entities can't cross a model's function call boundary, so they travel as
    * handle strings.
+   *
+   * @param bool $person_approved
+   *   Whether a person decided this exact call: it runs after its card was
+   *   approved, or the thread's owner ran it themself.
    */
-  public static function create(): Invoker {
-    return new Invoker(self::ID, InvokerCapability::EntitiesAsHandles);
+  public static function create(bool $person_approved = FALSE): Invoker {
+    return $person_approved
+      ? new Invoker(self::ID, InvokerCapability::EntitiesAsHandles, SiteAgentInvokerCapability::PersonApproved)
+      : new Invoker(self::ID, InvokerCapability::EntitiesAsHandles);
   }
 
 }
diff --git a/src/Tool/SiteAgentInvokerCapability.php b/src/Tool/SiteAgentInvokerCapability.php
new file mode 100644
index 0000000..df953c5
--- /dev/null
+++ b/src/Tool/SiteAgentInvokerCapability.php
@@ -0,0 +1,24 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Drupal\site_agent\Tool;
+
+/**
+ * What the Site Agent invoker tells a tool about the call it runs.
+ *
+ * A tool checks these with Invoker::has(), like Tool API's own
+ * InvokerCapability.
+ */
+enum SiteAgentInvokerCapability {
+
+  // A person decided this exact call before it ran: it runs after a person
+  // approved its card (bound to the payload hash, so the arguments are the
+  // ones shown), or the thread's owner ran it themself. Not set on a
+  // pre-approved call, including one that runs without a prompt after an
+  // earlier approval in "once" mode. A tool can treat such input as the
+  // person's own, for example by skipping a check that exists to catch a
+  // model misreporting what a person said.
+  case PersonApproved;
+
+}
diff --git a/src/Tool/ToolCallExecutor.php b/src/Tool/ToolCallExecutor.php
index c142194..3b340e9 100644
--- a/src/Tool/ToolCallExecutor.php
+++ b/src/Tool/ToolCallExecutor.php
@@ -126,11 +126,14 @@ final class ToolCallExecutor {
    *   The requesting account, which must be the current user.
    * @param string $log_message
    *   The revision log message for tools that take one: the thread reference.
+   * @param bool $person_approved
+   *   Whether a person decided this exact call, which the tool is told through
+   *   its invoker (SiteAgentInvokerCapability::PersonApproved).
    *
    * @return \Drupal\site_agent\Tool\PreparedCall
    *   The prepared call, ready or refused.
    */
-  public function prepare(OfferedTools $offered, string $function_name, array $arguments, AccountInterface $account, string $log_message = ''): PreparedCall {
+  public function prepare(OfferedTools $offered, string $function_name, array $arguments, AccountInterface $account, string $log_message = '', bool $person_approved = FALSE): PreparedCall {
     if ((int) $this->currentUser->id() !== (int) $account->id()) {
       throw new \LogicException('Tool calls run as the requesting account, which must be the current user.');
     }
@@ -194,7 +197,7 @@ final class ToolCallExecutor {
       return new PreparedCall($tool, $arguments, NULL, CallOutcome::AccessDenied, (string) $this->structureGate->getLockedReason());
     }
 
-    $instance = $this->toolManager->createInstance($tool->toolId(), [], SiteAgentInvoker::create());
+    $instance = $this->toolManager->createInstance($tool->toolId(), [], SiteAgentInvoker::create($person_approved));
     $definition = $instance->getPluginDefinition();
     assert($definition instanceof ToolDefinition);
     $log_input = $tool->classification->logInput;
diff --git a/tests/modules/site_agent_test/src/Plugin/tool/Tool/WriteNote.php b/tests/modules/site_agent_test/src/Plugin/tool/Tool/WriteNote.php
index 2093221..9b8c5b1 100644
--- a/tests/modules/site_agent_test/src/Plugin/tool/Tool/WriteNote.php
+++ b/tests/modules/site_agent_test/src/Plugin/tool/Tool/WriteNote.php
@@ -5,6 +5,7 @@ declare(strict_types=1);
 namespace Drupal\site_agent_test\Plugin\tool\Tool;
 
 use Drupal\Core\StringTranslation\TranslatableMarkup;
+use Drupal\site_agent\Tool\SiteAgentInvokerCapability;
 use Drupal\tool\Attribute\Tool;
 use Drupal\tool\ExecutableResult;
 use Drupal\tool\Tool\ToolOperation;
@@ -34,10 +35,16 @@ use Drupal\tool\TypedData\InputDefinition;
 )]
 class WriteNote extends NoteToolBase {
 
+  /**
+   * The state key of whether the last run's invoker said a person approved it.
+   */
+  public const PERSON_APPROVED = 'site_agent_test.person_approved';
+
   /**
    * {@inheritdoc}
    */
   protected function doExecute(array $values): ExecutableResult {
+    $this->state->set(self::PERSON_APPROVED, (bool) $this->invoker?->has(SiteAgentInvokerCapability::PersonApproved));
     $notes = $this->notes();
     $notes[$values['key']] = $values['value'];
     $this->saveNotes($notes);
diff --git a/tests/src/Kernel/PersonApprovedInvokerTest.php b/tests/src/Kernel/PersonApprovedInvokerTest.php
new file mode 100644
index 0000000..08a4720
--- /dev/null
+++ b/tests/src/Kernel/PersonApprovedInvokerTest.php
@@ -0,0 +1,102 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Drupal\Tests\site_agent\Kernel;
+
+use Drupal\site_agent\Approval\ApprovalMode;
+use Drupal\site_agent\Entity\ThreadInterface;
+use Drupal\site_agent\Entity\ToolCallInterface;
+use Drupal\site_agent\Tool\CallHandler;
+use Drupal\site_agent\Tool\CallOutcome;
+use Drupal\site_agent\Tool\OfferedTools;
+use Drupal\site_agent\Tool\ToolRunResult;
+use Drupal\site_agent_test\Plugin\tool\Tool\WriteNote;
+use PHPUnit\Framework\Attributes\Group;
+use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
+
+/**
+ * Tests that a tool is told when a person decided its call.
+ */
+#[Group('site_agent')]
+#[RunTestsInSeparateProcesses]
+final class PersonApprovedInvokerTest extends SiteAgentKernelTestBase {
+
+  /**
+   * The function name of the test write tool.
+   */
+  private const FUNCTION = 'site_agent_test__write_note';
+
+  /**
+   * A pre-approved call runs without the capability.
+   */
+  public function testPreApprovedCall(): void {
+    [$handler, $offered, $thread] = $this->setUpThread(ApprovalMode::PreApproved);
+    $call = $handler->handle($thread, $offered, 'pre', self::FUNCTION, ['key' => 'a', 'value' => 'one']);
+    $this->assertSame(CallOutcome::Success, $call->result?->outcome);
+    $this->assertFalse($this->personApproved());
+  }
+
+  /**
+   * An approved call runs with it.
+   */
+  public function testApprovedCall(): void {
+    [$handler, $offered, $thread] = $this->setUpThread(ApprovalMode::Always);
+    $this->assertSame(CallOutcome::Success, $this->approveAndRun($handler, $offered, $thread, 'approved')->outcome);
+    $this->assertTrue($this->personApproved());
+  }
+
+  /**
+   * A "once" call runs with it when approved, without it after.
+   */
+  public function testOnceCall(): void {
+    [$handler, $offered, $thread] = $this->setUpThread(ApprovalMode::Once);
+    $this->approveAndRun($handler, $offered, $thread, 'first');
+    $this->assertTrue($this->personApproved());
+    $call = $handler->handle($thread, $offered, 'second', self::FUNCTION, ['key' => 'b', 'value' => 'two']);
+    $this->assertSame(CallOutcome::Success, $call->result?->outcome);
+    $this->assertFalse($this->personApproved());
+  }
+
+  /**
+   * A call the thread's owner runs themself runs with it.
+   */
+  public function testOwnerCall(): void {
+    [$handler, $offered, $thread] = $this->setUpThread(ApprovalMode::Always);
+    $call = $handler->runAsOwner($thread, $offered, self::FUNCTION, ['key' => 'a', 'value' => 'one']);
+    $this->assertSame(CallOutcome::Success, $call->result?->outcome);
+    $this->assertTrue($this->personApproved());
+  }
+
+  /**
+   * Offers the write tool in the given mode and starts a thread.
+   *
+   * @return array{0: \Drupal\site_agent\Tool\CallHandler, 1: \Drupal\site_agent\Tool\OfferedTools, 2: \Drupal\site_agent\Entity\ThreadInterface}
+   *   The call handler, the offered tools and the thread.
+   */
+  private function setUpThread(ApprovalMode $mode): array {
+    $toolset = $this->createToolset(['site_agent_test:write_note' => $mode]);
+    $offered = \Drupal::service('site_agent.tool_list_builder')->build([$toolset], $this->owner);
+    return [\Drupal::service('site_agent.call_handler'), $offered, $this->startThread($this->owner)];
+  }
+
+  /**
+   * Makes a call that waits, approves it as the owner and runs it.
+   */
+  private function approveAndRun(CallHandler $handler, OfferedTools $offered, ThreadInterface $thread, string $call_id): ToolRunResult {
+    $arguments = ['key' => $call_id, 'value' => 'note'];
+    $call = $handler->handle($thread, $offered, $call_id, self::FUNCTION, $arguments);
+    $this->assertTrue($call->record->isPending());
+    \Drupal::service('site_agent.tool_call_recorder')->update($call->record, ToolCallInterface::STATUS_APPROVED, NULL, $this->owner);
+    return $handler->runApproved($call->record, $thread, $offered, self::FUNCTION, $arguments);
+  }
+
+  /**
+   * Whether the last run of the write tool was told a person approved it.
+   */
+  private function personApproved(): bool {
+    \Drupal::state()->resetCache();
+    return (bool) \Drupal::state()->get(WriteNote::PERSON_APPROVED);
+  }
+
+}
```
<!-- cspell:enable -->
