# PXE profiles

Network-install profiles for machines that do not yet have an operating system.

## Nothing is here yet, and that is deliberate

PXE means a DHCP server that answers boot requests. On a shared or unknown
network, that server answers *other people's* machines too, and a stray
netbooting host can find itself being installed. The rule for this platform is
absolute: **no PXE or DHCP on an unknown or shared network.**

Before anything lands in this directory, the following must be true and recorded
in `docs/phase-30b-real-infrastructure-inventory.md`:

1. An isolated install VLAN exists, with no route to any customer or office
   network.
2. Every machine that can hear the DHCP server on that VLAN is inventoried, and
   each carries an explicit `safety_class`.
3. The machine being installed is `REIMAGE_ALLOWED` with `allow_reimage: true`.

None of those are established, so there is no profile here to run.

## When they are

A profile pins the exact installer image and its checksum, and the resulting
kickstart or preseed never contains a password — the machine gets an
authorised key at first boot from the deployment controller, and no console
password at all.
