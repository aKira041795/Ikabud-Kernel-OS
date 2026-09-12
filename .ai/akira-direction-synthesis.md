# SYNTHESIS — Akira direction debate, 2026-09-12

Chair synthesis of an independent multi-model panel convened on the product owner's directive that
Akira "can also be a contender" and the thesis "AI driven but gated — a CMS that is intelligent but
also responsible in knowing the limits".

**Brief:** `.ai/akira-direction-debate.brief.md`
**Panellists (independent, no cross-visibility):**
- `.ai/akira-direction-panel-sol.md` — Codex Sol (openai-codex/gpt-5.6-sol), reasoning `high`
- `.ai/akira-direction-panel-flash.md` — DeepSeek Flash (deepseek-v4-flash)

Both were told disagreement was welcome, that "there is no wedge" was a legitimate answer, and to
ground claims in the repository while labelling measured fact versus inference. Both did. **Neither
converged on the chair's framing, and the panel falsified part of the amendment merged the same day
as PR #126.** That is recorded below as a correction, not a footnote.

---

## 1. Where the panel agrees (independently, which is why it matters)

Both panellists, from different lineages and different lanes, reached the same three-part sequence:

| # | Iteration | Sol | Flash |
|---|---|---|---|
| 1 | Admin/editorial surface (media + real editor + revision timeline, browser-proven) | first | first |
| 2 | **One** bounded machine actor via `Actor`/`Grant`/`ExecutionContext` | second | second |
| 3 | Portable proof export for one published revision (P4) | third | third |

Six further independent agreements:

1. **"Intelligent but gated" is currently a demo, not a product.** Sol: `depends`, leaning demo.
   Flash: `demo`, stated flatly. Neither would ask a buyer to pay for it today.
2. **Cut ARK Theme Studio** from the next iterations. Both. Flash: it competes with mature theme
   customisers, proves no substrate claim, and adds a second JS build on a shared-hosting target.
3. **Cut "installation profiles as editions."** Both. The profile manifests already exist and contain
   no capabilities or surfaces; renaming them into editions is packaging, not value.
4. **P5 (extension authority grants) stays deferred.** No third-party extension exists in this repo.
5. **Breadth before surface is the failure mode**, and the panel sees the existing 15-module suite as
   an instance of it: six modules with no declared routes, no user surface, and zero `{ikb_entity_view}`
   contracts of their own.
6. **Instrumentation is over-invested relative to users** — Workbench, census, frozen baselines, and
   150+ `.ai/` artifacts, against a product with no verified tenant.

## 2. Where the panel agrees with each other *against* the chair

The panel did not accept the brief's premise that the substrate is ready and only the surface is
missing. Both found that the **AI claim specifically is not merely unbuilt but misrepresented**, and
both reached that by reading the code:

- **`cms-akira-ai` is not AI.** It ships `_enabled: false`, has no provider SDK and no remote
  transport, and is a deterministic local summariser over already-published content.
- **The real AI seam is an echo.** `kernel/DiSyL/AI/` defaults to `EchoAiProvider`, which returns
  `[ai:MODEL] <the prompt>`. Its `Policy` object accumulates cost **in memory**; the file itself
  states the per-tenant DB-backed ceiling is future work.
- **AI governance currently sits outside the capability bus.** The review queue, AI audit trail and
  provider configuration are **JSON files under `storage/`**, and the AI admin endpoints are gated by
  `kernelRequireSuperadmin()` — a role check on a kernel-scoped surface, which by measured fact #3 of
  the brief has **no authority store at all**.
- **"Schedule" appears nowhere in the repository.**
- **The interactive "human approval" is a badge** — a `review='required'` attribute rendering
  "Draft — requires review", with a comment admitting the interactive version is a placeholder.

Flash's verdict on this is the sharpest line in either panel and is worth quoting exactly:

> "AI governance here is a parallel, file-based, role-gated stack — the exact 'reimplemented it
> locally' anti-pattern that P2 was written to condemn in `daily-ledger`."

That is a direct indictment of the amendment I merged hours earlier, which asserted Akira "already
holds every primitive needed" and that the thesis "costs no new substrate". **Both statements are
false as implemented.** The first is false because the AI path bypasses the bus; the second is false
because P4 requires a signing primitive that does not exist — `kernel/Crypto.php` is symmetric
encryption only, with no sign/verify path.

## 3. Corrections to the chair's own brief and amendment

Recorded because a debate that only corrects the panellists is not a debate:

| Chair's claim | Reality | Source |
|---|---|---|
| Akira "already holds every primitive needed"; thesis "costs no new substrate" | False. AI path bypasses the bus; P4 needs a new signing primitive | Flash (read `kernel/Crypto.php`, `kernel/DiSyL/AI/`) |
| An agent may "draft, summarise, suggest **and schedule**" | "Schedule" appears nowhere in the repo | Flash |
| Taxonomy "cannot be managed" | **Wrong** — `cms-akira-shell` exposes Categories CRUD via `akira.taxonomy.*@1` | Sol |
| "ARK theme: 21 templates" | Overstated as a single-theme figure: 21 across **all** ARK dirs, 11 under `akira-ark` itself | Sol |
| (not raised by the chair) | `daily-ledger` is **untracked** — 0 tracked files — and is the only revenue-bearing domain | Flash |
| (not raised by the chair) | Repo has **17 `module.json` in 3 module folders**, not the README's "68 across 37" | Flash |
| (not raised by the chair) | `node_modules` is **committed inside** the builder module tree | Flash |
| (not raised by the chair) | Module tests under `modules/*/tests/` are **not executed by the main CI test job** | Flash |

The final row is a verification-integrity finding of the same family as the exit-0 bootstrap bug
already documented in `copilot-instructions.md`: tests that exist, look like coverage, and never run.

### 3a. Chair verification of the panel's load-bearing claims

Panellists are not witnesses. Each claim that changes a decision was checked independently:

| Claim | Verified | How |
|---|---|---|
| `cms-akira-ai` is not AI | **Confirmed** | `module.json`: `_enabled: false`, "Deterministic local summary and keyword suggestions" |
| The AI seam echoes the prompt | **Confirmed** | `EchoAiProvider::complete()` returns `$text = "[ai:$model] " . $prompt` |
| The cost ceiling is not persisted | **Confirmed** | `Policy.php:16` — "4.6.1 will add: per-tenant DB-backed daily ceiling" |
| AI governance state is JSON under `storage/` | **Confirmed, via code not disk** | `AIGovernance`: `SETTINGS_FILE = 'ai-governance.json'`, `STORAGE_PATH . '/tenant-ai-settings.json'`, `glob($auditDir . '/*.json')`, `glob($dir . '/*.json')` for the queue |
| P4 needs a new signing primitive | **Confirmed** | `kernel/Crypto.php` exposes only `encryptString`/`decryptString`/`reEncrypt`/`currentKeyId`/`keyRingIds`/`hasKeyId` — `grep -cE "function (sign\|verify)"` returns **0** |

One nuance worth recording: the on-disk check initially found **no** AI state files, which appeared to
falsify that claim. It does not — `AIGovernance` creates them lazily, so absence on disk is expected
and the claim is substantiated by the write paths. The chair's first reading was wrong, not the
panellist's. This is recorded because the reverse error — accepting a claim because the files
happened to be present — would have been equally easy to make.


## 4. Where the panel disagrees with itself

**The wedge.**
- **Sol:** a candidate wedge, **low confidence** — small digital agencies publishing regulated client
  claims (credit unions, clinics, trade associations), on cheap shared hosting, with portable
  evidence. But Sol is explicit that this is an "underserved-price/package opportunity rather than a
  durable moat", since no incumbent is *technically* incapable of reproducing the outcome.
- **Flash:** the wedge framing is premature. "An AI may not publish" is **not a differentiator at
  all**, because every CMS already prevents that through ordinary role permissions and the drafting
  itself is commoditised. The only sellable part is the **grant-bound receipt plus off-server
  verification** — i.e. iterations #2 and #3, not the AI feature story.

The chair finds Flash's objection decisive on the narrower point and Sol's on the broader one. They
are compatible: **the wedge is not "AI that cannot publish" (table stakes); it is "a portable,
off-server-verifiable record of who authorised this publication, under which grant".** That is a
compliance product, and it happens to require the delegation primitive — not an AI product that
happens to have governance.

**What to do about it.** Both panellists, asked what would change their mind, named *talking to
buyers* rather than building more. Sol's cheapest test: five named agency/compliance prospects, a
clickable flow, and a request for a paid pilot — proceed only on two paying. Flash's, cheaper still:
stop asserting the AI claim entirely and put the proof/audit story in front of five buyers against
their incumbent; if nobody pays, the AI thesis was never the purchase reason.

## 5. Chair adjudication

1. **Accept the sequence**: admin surface → one bounded machine actor → proof export. It is
   independently derived, and it inverts nothing about the pillar order, because #1 depends on no
   unclosed primitive while #2 and #3 rest on P3 and P4 respectively.
2. **Accept the verdict that the gated-AI thesis is currently a demo.** The amendment's thesis section
   overstates shipped reality and must be corrected. Direction was the owner's to set; *status* is the
   chair's to report accurately, and it was reported too generously.
3. **Narrow the claim to the defensible one.** The differentiator is the **grant-bound, off-server
   verifiable authorization record** — not "our AI can't publish", which any CMS already does with a
   role.
4. **Cut Theme Studio and editions from the ordered direction**, and mark P5 deferred, per both
   panellists. Keep them as options, not an ordering.
5. **Add the AI-governance bypass as a named defect**, not a footnote: a file-based, superadmin-gated
   governance stack duplicating the capability bus is precisely what P2 exists to eliminate. Migrate
   it onto the bus or delete it — do not extend it.
6. **Verify the module-test/CI gap**, and fix it if confirmed. Untested code that looks tested is
   worse than untested code.
7. **Do not build toward the wedge further before a buyer conversation.** The panel's convergence on
   this is the most valuable output of the debate: the next unsure step is a *market* question, and no
   amount of additional substrate answers it.

## 6. Status of this debate

Recorded as **findings**, not as an approved contract. Items 2, 5 and 6 are correctness and
verification-integrity matters the chair intends to act on. Items 3, 4 and 7 change direction and
belong to the product owner.

**Unresolved:** whether the buyer is an agency, an obliged public body, or a vertical app already
running on the kernel; whether the kernel should serve a vertical app rather than ship a CMS at all
(Flash's closing question); and whether the wedge survives contact with buyers.

**Honest limit of this exercise:** two models reading one repository is not market evidence. Every
buyer, price and effort figure in both panels is the panellist's estimate, labelled as such by them,
and should be treated as a hypothesis to test rather than a finding to plan against.
