---
name: aim-discovery
description: The "grill" - a caring, structured discovery interview that gets a site talking about itself (purpose, audience, content, voice, policy) and writes what it learns into aim's site memory. Use when the user wants to interview someone about a new or existing site, bootstrap site memory from scratch, or asks to "run the grill"/"do the discovery interview".
---

# aim-discovery ("the grill")

Cross-examine someone about a website, with care, and turn what they say
into `aim_fact` site memory. This is the elicitation front end for aim's
memory pipeline (see `web/modules/custom/aim/CLAUDE.md`'s "Extraction"
section) - it does not build anything itself. Recipe/site generation from
what's gathered here is a separate, heavier step (CLAUDE.md decision 8, its
own dry-run/human-review gate) - out of scope for this skill.

## Tone: care before coverage

"Cross-examining" is the honest word for what this is, but do it gently.
The person being interviewed may not have thought about half of this yet -
that's normal, not a gap to fill by pressing harder.

- One topic at a time. Don't fire a checklist of questions in one message.
- Follow what they actually say before moving to the next planned topic. A
  tangent that reveals something real is worth more than sticking to script.
- "I don't know" or "doesn't matter" is a valid, completeable answer. Don't
  wheedle a firmer answer out of someone who genuinely doesn't have one yet.
- Reflect back what you heard before moving on ("so it sounds like...") -
  both to confirm you got it right and so they can correct course.
- No fixed question count. Some sites need five minutes, some need five
  conversations. Read the room.

## What "learning about itself" covers

Work through these areas as the conversation naturally allows, not
necessarily in this order. Not every site needs every section - a personal
blog doesn't need a support-hours conversation, skip what's genuinely not
relevant rather than forcing it.

1. **Purpose and audience.** What is this, who is it for, what should a
   visitor be able to do here. The elevator-pitch layer.
2. **Current state.** Existing site being rebuilt, or starting fresh?
   Anything that already works and must survive the rebuild?
3. **Content and structure.** What kinds of content does it need to
   publish or manage? Who creates it, how often?
4. **Voice and personality.** How should it sound? Formal or casual,
   playful or precise, any words/phrases it should never use? This is
   ordinary brand-voice territory, not a separate mechanism from the rest
   of site memory - treat it as just another fact category.
5. **Policy and constraints.** Compliance requirements, editorial rules,
   anything that must never happen (a wrong claim, an inaccessible pattern,
   a legal line).
6. **Operational facts, if relevant.** Support hours, escalation paths,
   anything a support agent or editor would need to know day to day.

## Writing what you learn into memory

Do not store the conversation itself - only a distillation, matching aim's
standing no-recording default (`aim` module's CLAUDE.md, "Extraction"
section; check there for the current state of that constraint before
assuming it still holds, since it was reopened for discussion on
2026-09-09).

At a natural checkpoint (end of a topic, or end of the session), not after
every single reply:

1. Write a short, clean summary of what was actually established in that
   section, as plain prose - a few sentences, not a transcript, no
   back-and-forth preserved.
2. Save it to a scratch file (use the session's scratchpad directory if one
   is available, otherwise a temp file under the project root you delete
   afterward).
3. Run `ddev drush aim:extract <file> --source=discovery:<short-topic-tag>
   --index`, from the project root (`/home/niklp/websites/aim` unless told
   otherwise). Drush's cwd is `web/`, not the project root, so pass an
   absolute container path (`/var/www/html/<path-from-project-root>`) if the
   file lives outside `web/`.
4. Almost everything here is `scope: site`. Voice/tone facts are still
   `site` scope (they describe the site, not a person). Only use `role`
   scope if something is explicitly about one role's responsibilities
   (e.g. "Editors must check links before publishing"), and `user` scope
   only if a fact is genuinely about the specific person you're talking to
   rather than the site.
5. Show the person the table of facts `aim:extract` created before moving
   on, so they can catch anything mis-extracted or misclassified.
6. Delete the scratch file once extraction has run - it was a distillation
   for the pipeline's benefit, not something to keep around.

## When to stop

There's no fixed end state. Stop a section when the person has nothing more
to add on that topic, not when a checklist is exhausted. It's fine to end a
whole session with several sections still untouched - note out loud which
ones weren't covered so a future session (with this same skill) can pick up
where this one left off, rather than starting over.
