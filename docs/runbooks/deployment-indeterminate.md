# A deployment stopped without an answer

## What you are seeing

`DeploymentWaitingForAPerson`, or a run sitting in `indeterminate` or
`needs_review` on the Deployments screen.

## What it means

The control plane asked the deployment controller to run a playbook against a
machine and either never learned whether it finished (indeterminate) or
finished and could not confirm the change (needs review). The machine may be
part-way through a change to `/etc`. Under the Timeout Rule nothing retries it
and nothing else runs on that machine until a person has looked.

## Check first

```
GET /admin/control-center/deployments      # waiting-for-a-person rows are first
```

Open the run. The steps say how far it got. Then go to the machine:

```bash
# on the deployment controller, the tree the run used
cd $INFRASTRUCTURE_IAC_PATH/ansible
ansible-playbook -i inventories/<environment> playbooks/<profile playbook>.yml --limit <machine> --check --diff
```

A clean check run means the change is there. Changes in the diff mean it is
not, or not all of it.

## Resolve

On the Deployments screen, **Resolve** the run with what you found and why.
"The change is there" records the run as completed; "not there" records it as
failed. Either way the machine is released and may be planned and run again.

A resolution is a statement by a person. The platform does not make it for
you, and it does not write facts on the strength of it: the next verify run
does that.

## Do not

- Start another run on the machine "to see": the platform refuses, and a
  second playbook over a half-applied first one is how a machine ends up in a
  state no profile describes.
- Resolve as completed because the alert is annoying. The facts will be wrong
  until the next verify, and drift detection will find the gap the next night.
