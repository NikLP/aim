#!/usr/bin/env python3
"""Score a /v1/systemone decision model on the aim decision points.

Tasks (sets in decision-eval-sets.json, hand-written, labels unverified):
  pairs  classifyPair(): choice ADD/UPDATE/NOOP/DELETE; confusion matrix,
         accuracy, and "unsafe" errors (data-losing UPDATE/DELETE when the
         right answer was ADD/NOOP, or DELETE on anything real).
  merges verifyMerge(): does the merged text keep both facts and add nothing.
  gate   reframed plausibility: is the candidate consistent with the
         context facts and worth storing.

Sequential, one request at a time, per-call timeout; stdlib only.

  decision-eval.py pairs tev1:4b
  decision-eval.py all laya:en --host http://localhost:11435
"""
import argparse, collections, json, pathlib, statistics as st, time, urllib.request

HERE = pathlib.Path(__file__).resolve().parent
OPTIONS = {
    "ADD": "The facts are genuinely different; keep both.",
    "UPDATE": "The candidate refines, corrects or supersedes the existing fact.",
    "NOOP": "The candidate restates the existing fact with no new information.",
    "DELETE": "The candidate should not exist as a memory at all (nonsensical or clearly erroneous). Use sparingly.",
}
PAIR_Q = ("Decide what to do with the candidate fact relative to the existing fact. "
          "When unsure, choose ADD: keeping both facts is always safe.")
MERGE_Q = ("Does the merged text keep every detail of both the kept and candidate facts, "
           "with nothing added, changed or contradicted?")
GATE_Q = ("Is the candidate a sensible fact about this library that is consistent with "
          "the existing facts?")


def call(host, model, state, questions):
    body = json.dumps({"model": model, "state": state, "questions": questions}).encode()
    req = urllib.request.Request(host.rstrip("/") + "/v1/systemone", body,
                                 {"content-type": "application/json"})
    start = time.time()
    answers = json.load(urllib.request.urlopen(req, timeout=120))["answers"]
    return time.time() - start, answers


def auc(pos, neg):
    return sum((p > n) + 0.5 * (p == n) for p in pos for n in neg) / (len(pos) * len(neg))


def best_threshold(pos, neg):
    best = (0, 0.5)
    for t in sorted(set(pos + neg)):
        acc = (sum(p >= t for p in pos) / len(pos) + sum(n < t for n in neg) / len(neg)) / 2
        best = max(best, (acc, t))
    return best


def run_pairs(host, model, items):
    labels = list(OPTIONS)
    conf = collections.defaultdict(collections.Counter)
    times, unsafe, hits = [], 0, 0
    for it in items:
        t, a = call(host, model, {"existing": it["existing"], "candidate": it["candidate"]},
                    {"decision": {"type": "choice", "instructions": PAIR_Q, "criteria": OPTIONS}})
        got = a["decision"]["choice"]
        conf[it["label"]][got] += 1
        times.append(t)
        hits += got == it["label"]
        if (got == "DELETE" and it["label"] != "DELETE") or \
           (got == "UPDATE" and it["label"] in ("ADD", "NOOP")):
            unsafe += 1
    print("pairs: accuracy %d/%d, unsafe errors %d" % (hits, len(items), unsafe))
    print("  truth\\got " + " ".join("%7s" % l for l in labels))
    for truth in labels:
        print("  %-9s " % truth + " ".join("%7d" % conf[truth][g] for g in labels))
    return times


def run_noul(name, host, model, items, good, question, state_of):
    scores = {"good": [], "bad": []}
    by_note = collections.defaultdict(list)
    times = []
    for it in items:
        t, a = call(host, model, state_of(it), {"p": {"type": "noul", "instructions": question}})
        s = a["p"]["noul"]
        side = "good" if it["label"] == good else "bad"
        scores[side].append(s)
        by_note[it["label"]].append(s)
        times.append(t)
    pos, neg = scores["good"], scores["bad"]
    acc, thr = best_threshold(pos, neg)
    at_half = (sum(p >= 0.5 for p in pos) + sum(n < 0.5 for n in neg)) / (len(pos) + len(neg))
    print("%s: AUC %.2f, accuracy @0.50 %.2f, best balanced %.2f @ %.2f (tuned on this set, optimistic)"
          % (name, auc(pos, neg), at_half, acc, thr))
    for label, vals in by_note.items():
        print("  %-14s n=%d min %.2f med %.2f max %.2f" % (label, len(vals), min(vals), st.median(vals), max(vals)))
    return times


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("task", choices=["pairs", "merges", "gate", "all"])
    ap.add_argument("model")
    ap.add_argument("--host", default="http://localhost:11434")
    ap.add_argument("--sets", default=str(HERE / "decision-eval-sets.json"))
    ap.add_argument("--limit", type=int, default=0, help="cap items per task (smoke test)")
    args = ap.parse_args()
    sets = json.load(open(args.sets))
    cut = lambda xs: xs[:args.limit] if args.limit else xs
    times = []
    start = time.time()
    if args.task in ("pairs", "all"):
        items = cut(sets["pairs"]) if not args.limit else sets["pairs"][::max(1, len(sets["pairs"]) // args.limit)]
        times += run_pairs(args.host, args.model, items)
    if args.task in ("merges", "all"):
        times += run_noul("merges", args.host, args.model, cut(sets["merges"]), "good", MERGE_Q,
                          lambda i: {"kept": i["kept"], "candidate": i["candidate"], "merged": i["merged"]})
    if args.task in ("gate", "all"):
        times += run_noul("gate", args.host, args.model, cut(sets["gate"]), "ok", GATE_Q,
                          lambda i: {"existing_facts": i["context"], "candidate": i["candidate"]})
    print("%s: %d calls, median %.2fs, max %.2fs, wall %.0fs"
          % (args.model, len(times), st.median(times), max(times), time.time() - start))


main()
