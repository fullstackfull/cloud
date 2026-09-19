# Reference topology

**REFERENCE — NON-PRODUCTION — DO NOT DEPLOY — DO NOT USE AS REAL INVENTORY**

`topology.php` is a model of what a valid Lynomia Cloud deployment looks like.
It is not the real datacenter inventory, it is not a production inventory, it
is not a secret store, and it is not evidence that any machine, address,
cluster or provider named in it exists.

Nothing in it is reachable. Nothing in it may be dialled. No preflight,
connection test or identity probe is ever pointed at one of its addresses.

## What it is for

- exercising the software against a production-shaped estate;
- showing an engineer onboarding a real estate, field by field, what Lynomia
  will ask them for;
- giving the development seeder, the tests and the documentation one source to
  agree with instead of four to drift apart.

## Three things that stay separate

| | Where it lives | Who changes it |
| --- | --- | --- |
| Reference topology | this directory, in git | a contributor, reviewed |
| Real operational configuration | the database, through the Control Center | an operator |
| Structural infrastructure | the private inventory and `infrastructure/ansible` | an infrastructure engineer |

Never merge them. The mapping from each reference field to its real
counterpart is in `docs/phase-30b-sim-gap-4-reference-topology.md` §23, and no
row of it is a source-code edit.

## The rules, all of them enforced

- `production`, `deployable` and `reachable` are fields, not sentences, and all
  three must be `false`.
- Every logical id is `ref-…`, lower case, so that one grep finds every one of
  them. `NoReferenceIdentifierIsRequiredByBusinessLogicTest` proves none is
  load-bearing in application source.
- Every address is in a range an RFC set aside for documentation (RFC 5737, RFC
  3849, RFC 9637) and every hostname is under a reserved domain (RFC 2606).
  Anything else is refused. In production the same values are refused from the
  other side, by `ReferenceValues` and `EndpointPolicy`.
- Every object that carries an address states `reference_only: true`.
- Zero credentials. No password, token, key, username or credential reference,
  and any field or value shaped like one is refused.
- Every provider names a controlled driver from `ProviderCatalogue`, never a
  real one, and no row is declared for production.
- Every monitoring target names a collector this application actually
  registers.
- Every reference resolves, exactly once, to an object of the expected kind,
  and nothing floats unconnected to a region.

`ReferenceTopologyValidator` is the one validator. The tests run it, and CI
runs the tests.

## Loading it

```
php artisan db:seed --class=InfrastructureSeeder
```

which calls `LoadReferenceTopologyForSimulation`. That name is deliberate:
loading a model and activating a real configuration are different acts, and
there is no method here that does the second. The loader refuses to run on a
production installation, refuses a topology whose marker claims to be
production, stamps every row it writes `development`, and writes no credential,
licence or capability — so nothing it creates can satisfy a production
readiness question.
