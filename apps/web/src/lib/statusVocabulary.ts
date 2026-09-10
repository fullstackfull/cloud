/**
 * The customer status vocabulary: every value the API can put in a `state`
 * or `status` field, with the tone it is shown in.
 *
 * One list, on purpose. The audit found the badge toning 66 of 117 statuses
 * and leaving the rest grey — so a failed build, an expired domain and a
 * payment that did not go through all looked like "Registered". Every status
 * is now here with an explicit tone, including `neutral` where neutral is the
 * honest answer, and a test fails the build when a translated status has no
 * entry or an entry has no translation.
 *
 * Tones mean something and are never chosen to look calm:
 *  - success   the thing is where the customer wants it
 *  - info      the platform is working on it, or it is a fact with no charge
 *  - warning   the customer should look: suspended, expiring, needs a person
 *  - danger    it did not work, or it is gone
 *  - neutral   a classification, not a condition (a chassis is "registered")
 *
 * Text remains authoritative. The tone is the second channel, never the only
 * one: every value here has a `status.*` sentence in both languages.
 */
export type StatusTone = 'success' | 'warning' | 'danger' | 'info' | 'neutral'

export const STATUS_TONES: Readonly<Record<string, StatusTone>> = {
  // Services, machines and accounts: alive, or working towards it.
  active: 'success',
  running: 'success',
  on: 'success',
  enabled: 'success',
  connected: 'success',
  managed: 'success',
  verified: 'success',
  restored: 'success',
  available: 'success',

  // Finished well.
  paid: 'success',
  succeeded: 'success',
  completed: 'success',
  applied: 'success',
  valid: 'success',
  ready_for_production: 'success',
  ready_to_sell: 'success',

  // The platform is on it. Not complete, and must not look complete.
  pending: 'info',
  queued: 'info',
  queued_for_provisioning: 'info',
  provisioning: 'info',
  preparing: 'info',
  installing: 'info',
  configuring: 'info',
  bmc_configuring: 'info',
  pxe_booting: 'info',
  reinstalling: 'info',
  restoring: 'info',
  validating: 'info',
  verifying: 'info',
  processing: 'info',
  powering_on: 'info',
  powering_off: 'info',
  reactivating: 'info',
  requested: 'info',
  scheduled: 'info',
  registration_pending: 'info',
  awaiting_registry: 'info',
  transfer_pending: 'info',
  pending_payment: 'info',
  open: 'info',
  draft: 'info',
  reserved: 'info',
  testing: 'info',
  preflight: 'info',
  planning: 'info',
  applying: 'info',
  discovered: 'info',
  profiled: 'info',
  configured: 'info',
  untested: 'info',
  ready: 'info',
  ready_for_discovery: 'info',
  ready_for_configuration: 'info',
  ready_for_test: 'info',
  ready_for_real_validation: 'info',
  // Domain actions a customer can take on a search result. Facts, not conditions.
  register: 'info',
  renew: 'info',
  transfer: 'info',
  redeem: 'info',
  premium: 'info',

  // Somebody should look, and the customer can still act.
  suspended: 'warning',
  past_due: 'warning',
  grace: 'warning',
  paused: 'warning',
  maintenance: 'warning',
  degraded: 'warning',
  draining: 'warning',
  stopped: 'warning',
  off: 'warning',
  quarantined: 'warning',
  expiring: 'warning',
  rotation_due: 'warning',
  disabled: 'warning',
  awaiting_approval: 'warning',
  connected_read_only: 'warning',
  delete_requested: 'warning',
  deleting: 'warning',
  removing: 'warning',
  redemption: 'warning',
  // Waiting on a person, ours. Must never look like "active".
  needs_review: 'warning',
  manual_review: 'warning',
  under_review: 'warning',

  // It did not work, or it is over.
  failed: 'danger',
  provisioning_failed: 'danger',
  provisioning_timeout: 'danger',
  payment_failed: 'danger',
  uncollectible: 'danger',
  expired: 'danger',
  cancelled: 'danger',
  terminated: 'danger',
  deleted: 'danger',
  void: 'danger',
  refused: 'danger',
  rejected: 'danger',
  revoked: 'danger',
  withdrawn: 'danger',
  transferred_away: 'danger',
  offline: 'danger',
  unavailable: 'danger',
  hardware_unavailable: 'danger',
  unsupported: 'danger',
  indeterminate: 'danger',
  missing: 'danger',
  invalid: 'danger',
  blocked: 'danger',
  auth_failed: 'danger',
  network_failed: 'danger',
  tls_failed: 'danger',
  licence_missing: 'danger',
  permission_insufficient: 'danger',
  provider_unavailable: 'danger',
  unknown: 'danger',

  // Classifications with no charge.
  registered: 'neutral',
  retired: 'neutral',
  closed: 'neutral',
  refunded: 'neutral',
  not_required: 'neutral',
  not_tested: 'neutral',
  not_ready: 'neutral',
}

export function toneFor(status: string): StatusTone {
  return STATUS_TONES[status] ?? 'neutral'
}
