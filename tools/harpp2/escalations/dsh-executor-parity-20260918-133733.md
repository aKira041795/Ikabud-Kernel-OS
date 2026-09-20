# Escalation: dsh executor parity

**Condition:** boundary

**Exact blocker:** The fixed Star Swarm objective is already green. HARPP v2 ran all five initial acceptance gates, recorded `objective_verified`, and dispatched zero lanes. Producing the required dsh objective lane would now require manufacturing a product/gate failure, weakening a check, substituting the fixed target, or editing the protected driver; the contract forbids all four.

**Evidence:** `docs/testing/dsh-executor-parity.md` records the exact commands, outputs, state, and journal event count.

**Exact next action:** Chair a replacement parity slice using a sandboxed objective whose pre-existing acceptance is currently red, or explicitly authorize a protected driver feature that forces one executor lane before initial acceptance. Do not rerun this green target expecting an executor dispatch.
