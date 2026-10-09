# C9 — Workflow Runtime & Durable Journey State
Status: DESIGNED, NOT IMPLEMENTED.

## Domain separation
Workflow definition != immutable WorkflowVersion != WorkflowExecution != NodeExecution != Timer != Task != C8 Effect. Mautic Campaign Builder is legacy compatibility/UI and potential future compiler, not runtime truth.
A Symfony Workflow component can manage fixed lifecycle transitions; Symfony Messenger carries work and retries. Durable journey graph state and timers persist in the database.

## Graph v1
ENTRY, CONDITION, WAIT, EFFECT, TASK, FINISH. Directed acyclic graph first; loops/parallel/join/subworkflow/event waits only with validated semantics. Definitions have typed expression IR and publish validation (dangling edges, cycles, unreachable FINISH, referenced resources).
Condition results MATCH/NO_MATCH/UNKNOWN, never UNKNOWN => FALSE by default.

## Durable execution
Each execution pins published version/hash. Node runs have unique (execution, node ID, run number), optimistic concurrency state. WAIT creates durable mos_workflow_timer row (due_at, claimed/fired/cancelled state), worker returns; scheduler claims due timers atomically and sends wake-up via outbox/Messenger. Late timer records scheduled vs actual time.
Effect node creates or loads exactly one C8 effect by stable business key derived from execution+node+run generation, then waits for receipt-driven outcome. UNKNOWN waits/reconciles; never chooses success/failure silently. Task is durable and completion idempotent. Event wait/timeout race single winner, when implemented.

## Enrollment/reentry
Audience entry or snapshot, form/event, explicit/manual command. Enrollment idempotency key based on originating event/context. Reentry policies NEVER/AFTER_COMPLETION/COOLDOWN/ALWAYS etc.; v1 IGNORE/START_IF_NO_ACTIVE.
Definition PAUSED default blocks new enrollments; pausing running executions is a separate operation. Cancel pending timers/tasks/undispatched effects; can't assume irreversible effect cancellation.

## Fault/rebuild
Node completion + next node + event/outbox transactional. Queue messages carry references. Crashes after commit are replay-safe; lease expiry not evidence about provider effect. Reconciler reports stuck states (WAITING without timer/task/effect/subscription). Preserve history and causal trace per execution.

## Upstream compatibility
Mautic action -> EFFECT/TASK; condition -> CONDITION; delayed action -> WAIT + action; decision -> event wait/timeout where source semantics proven. Compiler emits SUPPORTED/SUPPORTED_WITH_DIFFERENCE/LEGACY_ONLY/UNSUPPORTED. Shadow compare legacy and native node paths before per-workflow cutover.

## First full native journey
Audience snapshot -> enroll -> WAIT -> CONDITION(first name exists) -> policy C6 -> C8 CaptureEmailAdapter -> receipt -> FINISH.

## Gate
Timers survive restart, double resume doesn't double-run node, event vs timeout has one winner, new workflow version never mutates old runs, pending effect UNKNOWN never auto retries, cancellation prevents resurrection.
