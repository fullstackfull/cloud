# Closure threshold for the final audit (decided by the owner, after round eight)

The owner decided, after the re-audit after round eight, how the work closes:

1. Round nine's fixes are finished, each still verified by an independent verifier
   before merge, and the full suite is run with the three proofs.
2. One final independent audit follows. There are no further full re-audits between
   rounds.
3. In that final audit, and in the verification of round nine, an item blocks
   `SOFTWARE_CODE_COMPLETE` only when it touches one of:
   - **money** (a charge, credit, refund, wallet or invoice that is wrong);
   - **authorization** (a caller reaching what it may not, a takeover, a disclosure of
     another account or of whether a login exists);
   - **data loss** (a record destroyed or corrupted, a resource destroyed or orphaned);
   - **lockout** (a customer or operator unable to reach their account, service or money,
     with no supported way out).
   Every other item — a sentence the code does not meet outside those four, an owed
   oracle, a monitoring or documentation inaccuracy, a low-reach edge case — is recorded
   as a documented reservation in `docs/` and does not block.

This narrows `adjudication-rule.md` for the final audit only: Q1 and Q2 are still asked
of every item, and an item that fails Q1 and passes Q2 is still fixed when it falls in
one of the four classes above. The frozen statuses are not affected by this document.
