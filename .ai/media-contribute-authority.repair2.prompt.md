Read `.ai/media-contribute-authority.repair2.md` IN FULL. Stay on `feat/media-contribute-authority`; HEAD
is 32d70a5. static-analysis now PASSES (your PHPStan fix worked) and the fragment-cache guard has been
restored by the chair — it was legitimate, leave it alone.

The headline changes the picture: on main (#135 CI) this test SKIPPED with "tenant 994201 does not
activate required CLI module cms-akira-media". It had ZERO CI coverage. Your move to dynamic tenants was
the right instinct and it unmasked a dead test — it now runs in CI and fails:

  ✗ Phase 5A media scenario completes — CapabilityNotFoundException:
    No permitted capability providers for: akira.media.upload@1

Your activation fix was necessary but NOT sufficient. `moduleIsActive()` is now true (your new assertion
proves it) and the bus still refuses. That message is thrown at CapabilityBus.php:204 after
`applyPolicy()` returned an empty list — every provider was removed by CALLER/provider policy, not by
module activation. The test calls with caller module `cms-akira-media`. Find what removes it:
read `applyPolicy()` fully (~394-545), both the kernel-only selection path
(allow_providers/deny_providers) and the per-provider caller path (allow_callers/deny_callers from
`$provider['meta']['policy']`). The media manifest declares no capabilities.policy and your stub meta has
no policy key — so find which registration DOES carry one and why CI differs from your local run.
Instrument it if needed; do not guess.

Also note: `if ($registry->has($id)) { continue; }` means that if the real module registered the
capability first, YOUR STUB IS NEVER USED. That is a plausible fork between your local run and CI.

Resolution rule — prefer making it run. A prerequisite you can satisfy beats a skip. Only if you
establish that CI genuinely cannot provide it, emit a precise `SKIP: <reason>` in the repo's established
style (its siblings do exactly this). That is acceptable parity because main skipped here too. BUT the
skip must be a PREREQUISITE CHECK, never a catch — it must ask "is a tenant available for which the bus
permits this capability?", never "did the call fail, so let us skip". If you provision and the bus still
refuses, that is a finding: report it, do not wrap it.

If the root cause is in kernel/Capabilities/CapabilityBus.php or src/helpers/module-manager.php, STOP and
return ARCHITECTURE_DECISION_REQUIRED with evidence. Those are kernel files and need their own contract.

Capture the actual `reason: caller_policy` entry that CapabilityBus::logDenied writes to app.log — quote
it, do not describe it. And run the test locally with a writable fragment root (hold the www-data dir
aside as .disyl-fragments-hold, mkdir your own, restore afterwards and say so) so you verify against a
running test rather than a skip.

Report passed/failed/skipped and state explicitly which new media assertions RAN. Never report a skip as
a pass. Return the exact result block.
