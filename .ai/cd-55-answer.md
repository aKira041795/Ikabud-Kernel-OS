# CD-55 ANSWERED — additive registry changes are non-authorisable

recorded: 2026-09-15 · author: chair · director answer verbatim: **"Yes"**
question: *does the absolute prohibition cover additive, non-weakening changes to the capability registry?*
**answer: yes.** The prohibition stands as written, including for changes that only add.

## What this settles

1. **Option A of HARPP #100 is permanently unavailable**, not conditionally blocked. A guarded, audited,
   insert-only successor API is still a change to `kernel/Capabilities/`, and that path is never harness-editable.
   #100 should be closed on that basis.

2. **The boundary is categorical, which is why it works.** A rule that admitted an exception whenever the change
   was plausibly additive would be adjudicated by whoever wanted the change — and today that was me, arguing in
   good faith for a change I still believe was safe. The answer removes me from that judgement, which is the point.

3. **Evasion by aliasing is refused too.** Introducing a renamed duplicate of the chrome capability purely to
   obtain a wider caller list achieves by renaming exactly what D-Q4 (`widening_refused` /
   `operator_regrant_required`) forbids by design. The slice contract already forbids it; this answer confirms the
   reasoning behind that constraint rather than adding a new one.

## Consequence for the product — the honest position

Putting Theme Studio inside the shell **requires widening the chrome capability's caller list**, because the
browser spec asserts `/cms-akira-theme` stays at its own path *and* renders the shared navigation. Widening an
existing granted row is reserved to an **operator re-grant**. The harness cannot perform it.

**But this is a one-time legacy act, not a standing gap.** The committed seed
(`cms-akira-shell/helpers.php:48`) already names all four callers, so a tenant whose row is **created fresh**
receives four callers on insert. Only tenants whose row predates that seed change still carry three. (Inference
from the insert path — to be confirmed against a second tenant, not asserted.)

So closure looks like: an operator re-grants the chrome capability on legacy tenants; no code changes at all, and
the suite goes green. That is precisely what D-Q4 exists to require.

## Status of the running slice

`.ai/theme-studio-in-shell.contract.md` is dispatched on Sol and **still running**. It was instructed that a clear
*"no lawful route exists"* is an acceptable result, and — importantly — that the spec is forbidden scope so it
cannot green the suite by weakening the assertion.

Its contract file must **not** be edited while the run is live: `scopeConformance()` fails a run whose contract
revision moved after dispatch.

If it returns a lawful mechanism, it will have found something this chair's reasoning missed; if it returns the
negative result, that confirms the position above with independent evidence.
