# -*- coding: utf-8 -*-
"""Permanent schema regression check (Phase 4). Run from repo root: python tools/schema_verify.py
Asserts: one @graph per page, org/author @id resolve in-page, no review/aggregateRating,
no SearchAction artifact, every FAQPage question matches a visible h2/h3/h4."""
import io, re, json, glob, sys, html as htmllib
ok = bad = 0
issues = []
for f in sorted(glob.glob("dist/**/index.html", recursive=True)):
    fn = f.replace("\\", "/")
    s = io.open(f, encoding="utf-8", errors="replace").read()
    scripts = re.findall(r'<script type="application/ld\+json">(.*?)</script>', s, re.DOTALL)
    if not scripts:
        continue
    if len(scripts) != 1:
        issues.append("MULTIPLE LD BLOCKS: " + fn); bad += 1; continue
    try:
        g = json.loads(scripts[0])
    except Exception:
        issues.append("PARSE FAIL: " + fn); bad += 1; continue
    nodes = g.get("@graph", [g]); txt = json.dumps(nodes)
    if '"aggregateRating"' in txt or '"review"' in txt:
        issues.append("FAKE REVIEW SIGNAL: " + fn); bad += 1
    if "https://fabrioza.com/#organization" in txt and not any(
            isinstance(n, dict) and n.get("@id") == "https://fabrioza.com/#organization" for n in nodes):
        issues.append("ORPHAN ORG @id: " + fn); bad += 1
    if "#author-primary" in txt and not any(
            isinstance(n, dict) and str(n.get("@id", "")).endswith("#author-primary") for n in nodes):
        issues.append("ORPHAN AUTHOR @id: " + fn); bad += 1
    if "search_term_string" in txt:
        issues.append("SEARCHACTION ARTIFACT: " + fn); bad += 1
    if fn != "dist/index.html":
        body = s[s.index("<body"):] if "<body" in s else s
        heads = {re.sub(r"\W+", "", htmllib.unescape(re.sub("<[^>]+>", "", m))).lower()
                 for m in re.findall(r"<h[234][^>]*>(.*?)</h[234]>", body, re.DOTALL)}
        for n in nodes:
            if isinstance(n, dict) and n.get("@type") == "FAQPage":
                for q in n.get("mainEntity", []):
                    if re.sub(r"\W+", "", q.get("name", "")).lower() not in heads:
                        issues.append("ORPHAN FAQ Q: %s :: %s" % (fn, q.get("name", "")[:40])); bad += 1
    ok += 1
print("SCHEMA VERIFY: %d pages checked, %d failures" % (ok, bad))
for i in issues:
    print(" -", i)
sys.exit(1 if bad else 0)
