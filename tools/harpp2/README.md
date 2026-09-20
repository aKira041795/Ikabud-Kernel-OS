# tools/harpp2 — what this directory is

**Status: retired from use. Retained deliberately. Nothing live calls it.**

Written 2026-09-21 because the absence of this note caused three separate mis-classifications in
one session — "records, not code", then "RETIRED.md contradicts itself", then "retired and
forgotten". All three were wrong, and all three were avoidable by reading this file instead of
inferring from a listing.

## The three things mixed in here

| What | Where | Verdict |
|---|---|---|
| **Retired tooling** | the 13 `.php`/`.sh` files, `gates/` | Retired by `tools/RETIRED.md:13`; superseded by `tools/chair.php`. Kept because `RETIRED.md` documents it as a **worked example**, and its self-tests still pass. |
| **Audit records** | `runs/` (116 files, 5.8 MB), `state/` (3.7 MB), `objectives/`, `escalations/`, `projects/`, the 77 `.log` and 26 `.jsonl` | **Historical run records. Must not be deleted** — removing audit data is an absolute prohibition, not a judgement call. |
| **The survivor** | — | Already extracted: `kernel/Workbench/Development/AssertionChange.php` ("EXTRACTED from `tools/harpp2/assertions.php` when that harness was retired on 2026-09-19"). |

## Notes for anyone tempted to clean this up

- It is **excluded from retrieval** (`RetrievalIndex::RETIRED_PREFIXES`), so it is never briefed to a
  lane. It costs disk and nothing else.
- Do **not** delete the records. If the directory is ever moved, move it whole; splitting the code out
  would break the worked-example purpose that `RETIRED.md` states, and dropping the records would
  destroy provenance.
- `RETIRED.md`'s table says a directory path covers everything beneath it. That is about *use*, and it
  is correct: nothing here is in use. It is not a licence to delete the records.
