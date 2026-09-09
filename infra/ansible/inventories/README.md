# Inventories

Each host entry records metadata, never credentials. The schema below is
enforced by `infra/scripts/validate-inventory.py`, which CI runs on every push.

```yaml
some-host:
  ansible_host: <management address>       # required
  safety_class: DO_NOT_TOUCH               # required, one of the four classes
  allow_reimage: false                     # required to be true for destructive plays
  credentials_available: false             # do we hold working credentials, yes/no only
  purpose: "what this machine is for"      # required
  owner: "who authorises changes to it"    # required
```

Rules the validator enforces:

1. Every host declares `safety_class`, and it is one of the four known values.
2. `allow_reimage: true` is only legal on a host whose class is `REIMAGE_ALLOWED`.
3. `credentials_available` is a boolean. It never holds the credential.
4. No host var name or value looks like a secret (password, token, key, secret).
5. `purpose` and `owner` are non-empty, so no machine is present without a reason
   and somebody to ask.
