#!/usr/bin/env python3
"""Benchmark a /v1/systemone decision model as a fact-plausibility scorer.

Sends each fact as `state` with one Noul question and reports cold-load
time, per-fact latency, score ranges for true facts versus the control
sets in plausibility-controls.json, and how many each side the pass mark
gets right. Reads true facts from demo/seed-facts.json by default
(ADR-0033). Needs only the Python 3 standard library and a reachable
Ollama (0.35+) or Ollaya host.

  plausibility-benchmark.py tev1:4b
  plausibility-benchmark.py laya:en --host http://localhost:11435
  plausibility-benchmark.py tev1:4b --parallel 16 --threshold 0.6

For memory numbers, watch the service from another terminal:
  watch -n2 'systemctl show ollama -p MemoryCurrent -p MemoryPeak'
Restart the service first for a true cold-load measurement.
"""
import argparse
import concurrent.futures as cf
import json
import pathlib
import statistics as st
import sys
import time
import urllib.request

HERE = pathlib.Path(__file__).resolve().parent
QUESTION = "Is this a plausible, self-consistent factual statement?"


def load_true_facts(path):
    data = json.load(open(path))
    if isinstance(data, dict):
        return [f for group in data.values() for f in group]
    return list(data)


def make_caller(host, model):
    def call(text):
        body = json.dumps({
            "model": model,
            "state": text,
            "questions": {"p": {"type": "noul", "instructions": QUESTION}},
        }).encode()
        req = urllib.request.Request(
            host.rstrip("/") + "/v1/systemone", body,
            {"content-type": "application/json"})
        start = time.time()
        answer = json.load(urllib.request.urlopen(req, timeout=300))
        return time.time() - start, answer["answers"]["p"]["noul"]
    return call


def spread(values):
    return "min %.2f med %.2f max %.2f" % (min(values), st.median(values), max(values))


def main():
    root = HERE.parents[6]
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("model", help="model tag, e.g. tev1:4b or laya:en")
    parser.add_argument("--host", default="http://localhost:11434")
    parser.add_argument("--facts", default=str(root / "demo" / "seed-facts.json"),
                        help="JSON file of true facts (list, or object of lists)")
    parser.add_argument("--controls", default=str(HERE / "plausibility-controls.json"))
    parser.add_argument("--threshold", type=float, default=0.5)
    parser.add_argument("--parallel", type=int, default=0,
                        help="also time N requests serial versus parallel")
    args = parser.parse_args()

    call = make_caller(args.host, args.model)
    true_facts = load_true_facts(args.facts)
    controls = json.load(open(args.controls))

    start = time.time()
    call("Warmup statement.")
    print("%s cold load + first call: %.1fs" % (args.model, time.time() - start))

    def run(texts):
        results = [call(t) for t in texts]
        return [r[1] for r in results], [r[0] for r in results]

    true_scores, true_times = run(true_facts)
    print("true facts (%d, avg %d chars): %s" % (
        len(true_facts), sum(map(len, true_facts)) / len(true_facts), spread(true_scores)))
    print("latency per new fact: median %.2fs max %.2fs" % (
        st.median(true_times), max(true_times)))
    print("true passed at %.2f: %d/%d" % (
        args.threshold, sum(s >= args.threshold for s in true_scores), len(true_scores)))
    for name, texts in controls.items():
        scores, _ = run(texts)
        print("%s controls: %s, rejected %d/%d" % (
            name, spread(scores), sum(s < args.threshold for s in scores), len(scores)))
        print("  scores: %s" % [round(s, 2) for s in scores])

    if args.parallel:
        batch = [true_facts[i % len(true_facts)] for i in range(args.parallel)]
        start = time.time()
        for t in batch:
            call(t)
        serial = time.time() - start
        start = time.time()
        with cf.ThreadPoolExecutor(args.parallel) as pool:
            list(pool.map(call, batch))
        print("%d serial %.1fs, parallel %.1fs (repeats may hit the prefix cache)" % (
            args.parallel, serial, time.time() - start))


if __name__ == "__main__":
    sys.exit(main())
