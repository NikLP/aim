# Ingest test data (fake)

Synthetic Harbourside library facts for exercising write, consolidation
and the ingest form end to end. Safe to send to a hosted model. All
invented; user-scope facts use uid 3 (`alexchen`).

| File | Use |
| --- | --- |
| `facts.json` | 85 facts across site, role, user, case and entity scopes, in the `drush aim:remember --file` shape. |
| `lines-site.txt` | 12 site-scope facts, one per line, for a smoke test of the form's one-fact-per-line mode (no model). |
| `expected.json` | What each fact in `facts.json` should do, by index: `new`, `restatement` (should retire as NOOP), `update` (should supersede the existing fact), `contradiction`, `junk`, `blocked` (the guardrails must reject it). |
| `notes-staff-meeting.txt`, `email-summer-reading.txt`, `transcript-website-call.txt` | Prose for the extraction path (`drush aim:extract`): chatty text with a few real facts and some noise to ignore. |

Run it:

```bash
ddev snapshot --name pre-ingest-test
ddev exec drush aim:remember --file=web/modules/custom/aim/tests/ingest-test/facts.json
ddev exec drush aim:extract web/modules/custom/aim/tests/ingest-test/notes-staff-meeting.txt --source=ingest-test --index
ddev drush aim:consolidate --dry-run
```

Undo: `ddev snapshot restore pre-ingest-test`, or delete by source
(`ingest-test`).
