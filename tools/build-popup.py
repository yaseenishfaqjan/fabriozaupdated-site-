# -*- coding: utf-8 -*-
"""Attach the feature popup to every page that should carry it.

    python -I tools/build-popup.py            # dry run
    python -I tools/build-popup.py --apply

The popup itself lives in dist/assets/fab-popup.js and decides for itself
whether to run: it re-checks the path, the 7-day localStorage stamp and the
viewport before rendering anything. This script only decides which pages LOAD
it, which is a second, cheaper guard - a page in EXCLUDE never even fetches
the file.

Exclusions are the page the popup advertises, anywhere a visitor is mid
enquiry, and the two interactive tools. Interrupting somebody halfway through
building a kit is the worst possible moment to ask for their attention.

As with build-footer.py: the tag is injected as a SINGLE LINE with no newline
of its own, so no file's mixed line endings change and no diff balloons.
"""
import pathlib
import re
import sys

REPO = pathlib.Path(__file__).resolve().parent.parent
DIST = REPO / "dist"
APPLY = "--apply" in sys.argv

# Bump when fab-popup.js changes: dist/assets is served immutable for a year.
TAG = '<script src="/assets/fab-popup.js?v=20261127" defer></script>'
MARK = "/assets/fab-popup.js"

EXCLUDE = {
    "custom-fabric-development",   # the page it points at
    "contact",                     # mid-enquiry
    "team-designer",               # interactive tool
    "design",                      # the configurator, same reason
}


def main():
    pages = sorted(DIST.rglob("index.html"))
    added = skipped = already = 0
    print(f"mode: {'APPLY' if APPLY else 'DRY RUN'}   pages: {len(pages)}\n")

    for p in pages:
        rel = "" if p.parent == DIST else p.parent.relative_to(DIST).as_posix()
        top = rel.split("/")[0] if rel else ""
        if top in EXCLUDE:
            skipped += 1
            print(f"  SKIP     /{rel}")
            continue

        raw = p.read_bytes()
        crlf = raw.count(b"\r\n")
        h = raw.decode("utf-8")

        if MARK in h:
            already += 1
            continue
        i = h.rfind("</body>")
        if i < 0:
            skipped += 1
            print(f"  SKIP     /{rel}  (no </body>)")
            continue

        out = (h[:i] + TAG + h[i:]).encode("utf-8")
        if out.count(b"\r\n") != crlf:
            sys.exit(f"FATAL: /{rel} line endings changed. Refusing to write.")
        if APPLY:
            p.write_bytes(out)
        added += 1

    print(f"\nadded={added}  already={already}  skipped={skipped}")
    if not APPLY:
        print("\nDry run. Re-run with --apply to write.")


if __name__ == "__main__":
    main()
