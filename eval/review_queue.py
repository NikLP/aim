#!/usr/bin/env python3
"""Turn logged assistant chat calls into a review queue (markdown).

Usage: python3 eval/review_queue.py [first_ai_log_id] > eval/REVIEW-QUEUE.md
Reads ai_log through ddev drush. One entry per visitor question: the last
logged call for that question holds the whole exchange (tool calls, tool
results, final answer).
"""
import re
import subprocess
import sys

since = int(sys.argv[1]) if len(sys.argv) > 1 else 0
sql = ("select id, replace(replace(prompt,'\\\\','\\\\\\\\'),char(9),' '), response_text from ai_log "
       "where operation_type='chat' and id>%d order by id" % since)
out = subprocess.run(['ddev', 'drush', 'sql:query', sql], capture_output=True, text=True, check=True).stdout
print('# Review queue\n\nOne row per visitor question. Judge against MEMORY-SNAPSHOT.md.\n')
turns = []
for line in out.splitlines():
    parts = line.split('\t')
    if len(parts) < 3:
        continue
    id_, prompt, answer = parts[0], parts[1], '\t'.join(parts[2:])
    # Each call carries the whole thread so far; the current question is the
    # last user message, and everything after it belongs to this turn.
    segs = re.split(r'(?:^|\\n)user\\n', prompt)
    cur = segs[-1]
    q = cur.split('\\n')[0]
    if turns and turns[-1]['q'] == q and turns[-1]['n'] == len(segs):
        turns[-1].update(id=id_, cur=cur, answer=answer)
    else:
        turns.append({'q': q, 'n': len(segs), 'id': id_, 'cur': cur, 'answer': answer})
for t in turns:
    tools = re.findall(r'\[calls ([a-z_]+)\]', t['cur'])
    results = re.findall(r'tool \(([a-z_]+)\)\\n(.*?)(?=\\n(?:tool|assistant)|$)', t['cur'])
    ans = t['answer'].replace('\\n', ' ').strip()
    if ans in ('', 'NULL'):
        ans = '(no answer logged, the call failed or was cut off)'
    print('## ai_log %s: %s\n' % (t['id'], t['q']))
    print('Tools: %s\n' % (', '.join(tools) or 'none'))
    for name, res in results:
        print('- %s -> %s' % (name, res.replace('\\n', ' ')[:200]))
    print('\nAnswer: %s\n' % ans)
    print('Pre-verdict: (Claude fills)  Nik: Y / N  Reason:\n')
