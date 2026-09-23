---
paths:
  - 'app/Jobs/**'
---

# Jobs

## Jobs carry scalar ids and rebuild the DTO in handle()
Constructor takes ids and scalars, never a DTO or a model — a serialized payload goes stale when a deploy lands mid-queue. Rebuild the DTO inside `handle()` and invoke the Action.

Always `ShouldQueue`; dispatch with `afterCommit()` so a job can never observe uncommitted state. `ShouldBeUnique` is an optimization only.

`failed()` moves work to a terminal review state (`needs_review`), never back to `pending` and never to a state that would let the money be sent again blindly.
