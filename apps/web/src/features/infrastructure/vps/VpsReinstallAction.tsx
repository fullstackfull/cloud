import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { SelectField } from '@/components/SelectField'
import { TextareaField } from '@/components/TextareaField'
import { useWatchOperations } from '@/features/operations/watchChannel'
import { RESOURCE_FAMILIES } from '@/features/resources/resourcePaths'
import { newIdempotencyKey } from '@/lib/api'
import { useVpsReinstall, useVpsTemplates } from '@/lib/queries'
import type { VirtualMachine } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * Rebuilding a machine: the choice, the warning, and the typed hostname.
 *
 * One component for the list row and for the machine's own danger zone, for
 * the reason the audit gave: two paths to one destructive mutation is two
 * chances to lose the confirmation or the idempotency key.
 *
 * Wave 3 adds the two inputs the endpoint has always accepted and no screen
 * ever offered — an operating system and public SSH keys:
 *
 *  - The images come from the machine's own list, which the API resolves with
 *    exactly the rule it applies to a submitted id. Nothing is hard-coded
 *    here: a portal that offered "Ubuntu 24.04" because Ubuntu is popular
 *    would be offering a rebuild the next request refuses.
 *  - Keeping the current image is the first option and the default, because
 *    that is what the endpoint does with no template and what somebody who
 *    just wants a clean machine means.
 *  - The key field appears only for an image that can be configured on first
 *    boot. Without that the platform cannot install a key, and a form that
 *    took one anyway would be collecting something it intends to drop.
 *
 * Public keys only. There is no field for a private key anywhere in this
 * platform, and none is ever logged: the textarea's contents go into the
 * request body and nowhere else.
 */
export function VpsReinstallAction({ vm }: { vm: VirtualMachine }) {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()
  const reinstall = useVpsReinstall()
  const { watch } = useWatchOperations()

  const [open, setOpen] = useState(false)
  const [templateId, setTemplateId] = useState('')
  const [keys, setKeys] = useState('')

  // Asked for only while the dialogue is open: a list page has no use for a
  // machine's image catalogue.
  const { data: templates } = useVpsTemplates(vm.id, open)

  const failure = describeError(reinstall.error)
  const chosen = templates?.find((template) => template.id === templateId)
  const keysAllowed = chosen?.supports_ssh_keys ?? false

  function close() {
    setOpen(false)
    setTemplateId('')
    setKeys('')
    reinstall.reset()
  }

  return (
    <>
      <Button
        size="sm"
        variant="danger"
        /*
         * Disabled on exactly the facts the API refuses on — the service, the
         * hypervisor, and anything queued, running or stranded on the service
         * — as the API itself reports them. `is_operable` alone left a machine
         * whose rebuild had timed out with an enabled button and a 409 behind
         * it: a door with a sign rather than a closed door.
         */
        disabled={!vm.actions.reinstall}
        onClick={() => { setOpen(true); }}
      >
        {t('vps.actions.reinstall')}
      </Button>

      <ConfirmDialog
        open={open}
        title={t('vps.reinstall.title', { hostname: vm.hostname })}
        body={
          <div className="flex flex-col gap-3">
            <p className="font-medium text-[var(--danger-text)]">{t('vps.reinstall.warning')}</p>
            <p>{t('vps.reinstall.advice')}</p>

            <SelectField
              label={t('vps.reinstall.templateLabel')}
              hint={t('vps.reinstall.templateHint')}
              value={templateId}
              onChange={(event) => { setTemplateId(event.target.value); }}
              options={[
                { value: '', label: t('vps.reinstall.sameImage') },
                ...(templates ?? []).map((template) => ({
                  value: template.id,
                  label: `${template.name} · ${template.os_version} · ${template.architecture}`,
                })),
              ]}
            />

            {keysAllowed ? (
              <TextareaField
                label={t('vps.reinstall.sshLabel')}
                hint={t('vps.reinstall.sshHint')}
                dir="ltr"
                rows={4}
                className="technical text-xs"
                value={keys}
                spellCheck={false}
                onChange={(event) => { setKeys(event.target.value); }}
                placeholder="ssh-ed25519 AAAA…"
              />
            ) : chosen === undefined ? null : (
              <p className="text-xs text-[var(--text-muted)]">{t('vps.reinstall.noSsh')}</p>
            )}
          </div>
        }
        /*
         * The machine's own hostname, typed back. A boolean confirmation is a
         * boolean a client library sends by default.
         */
        requiredPhrase={vm.hostname}
        requiredPhraseLabel={t('vps.reinstall.phraseLabel')}
        confirmLabel={t('vps.reinstall.confirmLabel')}
        loading={reinstall.isPending}
        {...(failure === null ? {} : { error: failure.message })}
        onConfirm={(phrase) => {
          const sshKeys = keys
            .split('\n')
            .map((line) => line.trim())
            .filter((line) => line !== '')

          reinstall.mutate(
            {
              id: vm.id,
              // Sent as typed. The server compares it against the machine's
              // hostname and is the one that decides.
              confirm_hostname: phrase,
              ...(templateId === '' ? {} : { template_id: templateId }),
              ...(keysAllowed && sshKeys.length > 0 ? { ssh_keys: sshKeys } : {}),
              idempotencyKey: newIdempotencyKey(),
            },
            {
              onSuccess: (accepted) => {
                /*
                 * A rebuild takes minutes and the customer will not sit on
                 * this page for them. The watcher follows it from wherever
                 * they go, and says `needs_review` as itself if it stops for
                 * a person — never as a failure, which would invite a second
                 * rebuild of a half-built machine.
                 */
                watch(accepted, {
                  actionKey: 'operations.actions.rebuild',
                  href: RESOURCE_FAMILIES.vps.detail(vm.id),
                  invalidate: ['vps', 'services', 'activity', 'overview'],
                })

                close()
              },
            },
          )
        }}
        onCancel={close}
      />

      {failure === null || open ? null : (
        <Alert tone="error" requestId={failure.requestId}>
          {failure.message}
        </Alert>
      )}
    </>
  )
}
