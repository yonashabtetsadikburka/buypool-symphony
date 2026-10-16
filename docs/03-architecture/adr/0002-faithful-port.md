# ADR 0002: Port faithfully first, improve later

## Status

Accepted · 2026-10-16

## Context

The original implementation contains rules that may look odd but can exist for good reasons, for example how prices change across tiers, how quantities and remainders are allocated between participants, how a campaign moves between states, or what happens when a payment webhook arrives twice.

If the code is rewritten and improved at the same time, a difference in behavior cannot be classified as a bug in the port or as an intentional change.

## Decision

Each module is ported in two steps:

1. **Parity.** Port the logic so that it matches the original behavior. Verify it with reference cases: pairs of input and expected output taken from the rules of the original implementation (about 30 cases for pricing, allocation and states). These run as PHPUnit tests in `backend/tests/Parity/`.
2. **Improvement.** Only after parity is reached, change the behavior on purpose. Each change goes in a separate commit (`fix:` or `feat:`), updates the tests, and gets its own ADR when significant.

Known deviations from the original are listed in the documentation. Security fixes (for example input validation, authorization checks, webhook signature verification) are allowed from the start and are always listed as deviations.

Reference cases contain no personal data or secrets, so they are kept in the repository. The original source code and the private analysis notes stay outside the repository.

## Alternatives considered

| Option | Why not |
|---|---|
| Improve while porting | Hides the cause of every difference between old and new behavior. |
| Write everything from the user stories only | Cleaner, but throws away edge cases that the original code already handles. |

## Consequences

Positive:

- Confidence that the port behaves like the original.
- Every deliberate change is traceable to a commit and, when needed, to an ADR.

Negative:

- Reference cases take effort to produce and cannot cover every situation.
- Parity covers business rules, not the whole system: endpoints and integrations also need functional tests.