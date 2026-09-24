import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { Field } from '@/components/Field'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { PageHeader } from '@/components/PageHeader'
import {
  useChangeOperatorRoles,
  useInviteOperator,
  useOperators,
  usePermissionCatalogue,
  useRoles,
  useSetRolePermissions,
} from '@/lib/controlCenterQueries'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * Who may operate the platform.
 *
 * There was no screen, no endpoint and no console command: `role.manage` was
 * declared in the permission catalogue and referenced by nothing, so a
 * deployment had exactly as many operators as it was born with — and a
 * production deployment was born with none, because the only seeder that
 * assigns anybody refuses to run in production.
 *
 * The refusals are the interesting part of this screen and are shown as
 * refusals rather than hidden as disabled controls: an operator who is told
 * "that is not yours to grant" has learned something, and one whose button is
 * greyed out has not.
 */
export function OperatorsPage() {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const operators = useOperators(search === '' ? undefined : search)
  const roles = useRoles()
  const [inviting, setInviting] = useState(false)

  return (
    <>
      <PageHeader
        title={t('admin.operators.title')}
        description={t('admin.operators.subtitle')}
        actions={<Button onClick={() => { setInviting((open) => !open) }}>{t('admin.operators.invite')}</Button>}
      />

      {inviting ? <InviteForm onDone={() => { setInviting(false) }} /> : null}

      <Card title={t('admin.operators.people')}>
        <div className="mb-3">
          <Field
            label={t('admin.operators.search')}
            value={search}
            onChange={(e) => { setSearch(e.target.value) }}
          />
        </div>

        {operators.isPending ? (
          <Loading />
        ) : operators.error ? (
          <LoadFailure error={operators.error} />
        ) : operators.data.data.length === 0 ? (
          <p className="text-sm text-[var(--text-muted)]">{t('admin.operators.none')}</p>
        ) : (
          <ul className="flex flex-col gap-2" aria-label={t('admin.operators.people')}>
            {operators.data.data.map((operator) => (
              <li key={operator.id} className="rounded-lg border border-[var(--border-subtle)] p-3" aria-label={operator.email}>
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <p>
                    <span className="font-medium">{operator.name}</span>{' '}
                    <span className="technical text-xs text-[var(--text-muted)]">{operator.email}</span>
                  </p>
                  <span className="flex flex-wrap items-center gap-2 text-xs text-[var(--text-muted)]">
                    {operator.is_privileged ? <span>{t('admin.operators.privileged')}</span> : null}
                    {/* The commonest reason for "I added them and nothing
                        happened": an invitation nobody has acted on yet. */}
                    {operator.has_signed_in ? null : <span>{t('admin.operators.neverSignedIn')}</span>}
                    {operator.two_factor_enabled ? <span>{t('admin.operators.twoFactorOn')}</span> : null}
                  </span>
                </div>
                <RoleEditor operator={operator.id} current={operator.roles} available={roles.data?.data ?? []} />
              </li>
            ))}
          </ul>
        )}
      </Card>

      <RoleCatalogue />
    </>
  )
}

function RoleEditor({ operator, current, available }: {
  operator: string
  current: string[]
  available: { name: string; label: string; is_staff_role: boolean }[]
}) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const change = useChangeOperatorRoles(operator)
  const [selected, setSelected] = useState<string[]>(current)
  const failure = describe(change.error)

  const staffRoles = available.filter((role) => role.is_staff_role)
  const dirty = selected.length !== current.length || selected.some((role) => !current.includes(role))

  return (
    <div className="mt-2 flex flex-col gap-2">
      <ul className="flex flex-wrap gap-3" aria-label={t('admin.operators.rolesFor', { email: operator })}>
        {staffRoles.map((role) => (
          <li key={role.name}>
            <label className="flex items-center gap-1.5 text-sm">
              <input
                type="checkbox"
                checked={selected.includes(role.name)}
                onChange={(e) => {
                  setSelected((held) => (e.target.checked
                    ? [...held, role.name]
                    : held.filter((name) => name !== role.name)))
                }}
              />
              {role.label}
            </label>
          </li>
        ))}
      </ul>
      {/*
        * The refusals reach the screen verbatim. "You cannot change your own
        * roles" and "that would leave the platform with no administrator" are
        * the two an operator most needs to read, and neither is guessable
        * from a disabled button.
        */}
      {failure === null ? null : <Alert tone="error">{failure.message}</Alert>}
      {dirty ? (
        <div className="flex justify-end">
          <Button
            loading={change.isPending}
            onClick={() => { change.mutate({ roles: selected }) }}
          >
            {t('admin.operators.saveRoles')}
          </Button>
        </div>
      ) : null}
    </div>
  )
}

function RoleCatalogue() {
  const { t } = useTranslation()
  const roles = useRoles()
  const permissions = usePermissionCatalogue()
  const [open, setOpen] = useState<string | null>(null)

  return (
    <Card title={t('admin.operators.roles')} description={t('admin.operators.rolesSubtitle')}>
      {roles.isPending ? (
        <Loading />
      ) : roles.error ? (
        <LoadFailure error={roles.error} />
      ) : (
        <ul className="flex flex-col gap-2" aria-label={t('admin.operators.roles')}>
          {roles.data.data.map((role) => (
            <li key={role.name} className="rounded-lg border border-[var(--border-subtle)] p-3" aria-label={role.label}>
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <span className="font-medium">{role.label}</span>
                <span className="flex items-center gap-2 text-xs text-[var(--text-muted)]">
                  {t('admin.operators.heldBy', { count: role.operators })}
                  {role.permissions_are_editable ? (
                    <Button variant="ghost" onClick={() => { setOpen((current) => (current === role.name ? null : role.name)) }}>
                      {t('admin.operators.editPermissions')}
                    </Button>
                  ) : null}
                </span>
              </div>
              {/*
                * Super Admin's list is empty and its authority is total. Said
                * plainly, because a screen that showed "0 permissions" beside
                * the most powerful role in the platform would be telling an
                * operator the opposite of the truth — and it offers no editor,
                * because editing that list would change nothing.
                */}
              <p className="mt-1 text-xs text-[var(--text-muted)]">
                {role.grants_everything
                  ? t('admin.operators.grantsEverything')
                  : t('admin.operators.permissionCount', { count: role.permissions.length })}
              </p>
              {open === role.name ? (
                <PermissionEditor
                  role={role.name}
                  held={role.permissions}
                  catalogue={permissions.data?.data ?? []}
                  onDone={() => { setOpen(null) }}
                />
              ) : null}
            </li>
          ))}
        </ul>
      )}
      {permissions.data === undefined ? null : (
        <p className="mt-3 text-xs text-[var(--text-muted)]">
          {t('admin.operators.catalogueSize', { count: permissions.data.data.length })}
        </p>
      )}
    </Card>
  )
}

/**
 * What a role may do, chosen from the catalogue rather than from memory.
 *
 * Grouped by the prefix each permission already carries, because fifty-nine
 * of them in one column is a list nobody reads, and the eleven that no role
 * held are the ones an operator is most likely to be looking for.
 */
function PermissionEditor({ role, held, catalogue, onDone }: {
  role: string
  held: string[]
  catalogue: { name: string; group: string; held_by_default_roles: string[] }[]
  onDone: () => void
}) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const save = useSetRolePermissions(role)
  const [selected, setSelected] = useState<string[]>(held)
  const failure = describe(save.error)

  const groups = new Map<string, typeof catalogue>()

  for (const permission of catalogue) {
    groups.set(permission.group, [...(groups.get(permission.group) ?? []), permission])
  }

  return (
    <form
      aria-label={t('admin.operators.permissionsFor', { role })}
      className="mt-3 flex flex-col gap-3"
      onSubmit={(event) => {
        event.preventDefault()
        save.mutate({ permissions: selected }, { onSuccess: onDone })
      }}
    >
      <div className="grid gap-3 sm:grid-cols-2">
        {[...groups.entries()].map(([group, items]) => (
          <fieldset key={group} className="flex flex-col gap-1">
            <legend className="technical text-xs font-medium text-[var(--text-muted)]">{group}</legend>
            {items.map((permission) => (
              <label key={permission.name} className="flex items-center gap-1.5 text-sm">
                <input
                  type="checkbox"
                  checked={selected.includes(permission.name)}
                  onChange={(e) => {
                    setSelected((current) => (e.target.checked
                      ? [...current, permission.name]
                      : current.filter((name) => name !== permission.name)))
                  }}
                />
                <span className="technical text-xs">{permission.name}</span>
                {/*
                  * The eleven that no default role holds. Marked, because
                  * they are reachable only through the super-admin bypass
                  * until somebody grants them — and until this screen
                  * existed, nobody could.
                  */}
                {permission.held_by_default_roles.length === 0
                  ? <span className="text-xs text-[var(--text-muted)]">{t('admin.operators.heldByNoRole')}</span>
                  : null}
              </label>
            ))}
          </fieldset>
        ))}
      </div>
      {failure === null ? null : <Alert tone="error">{failure.message}</Alert>}
      <div className="flex justify-end gap-2">
        <Button type="button" variant="ghost" onClick={onDone}>{t('common.cancel')}</Button>
        <Button type="submit" loading={save.isPending}>{t('admin.operators.savePermissions')}</Button>
      </div>
    </form>
  )
}

function InviteForm({ onDone }: { onDone: () => void }) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const invite = useInviteOperator()
  const roles = useRoles()
  const [email, setEmail] = useState('')
  const [name, setName] = useState('')
  const [selected, setSelected] = useState<string[]>([])
  const failure = describe(invite.error)
  const fieldError = (field: string): string | undefined => failure?.fields?.[field]?.[0]

  return (
    <Card>
      <form
        aria-label={t('admin.operators.invite')}
        className="flex flex-col gap-3"
        onSubmit={(event) => {
          event.preventDefault()
          invite.mutate({ email: email.trim(), name: name.trim(), roles: selected }, { onSuccess: onDone })
        }}
      >
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t('admin.operators.email')} type="email" value={email} onChange={(e) => { setEmail(e.target.value) }} error={fieldError('email')} dir="ltr" required />
          <Field label={t('admin.operators.name')} value={name} onChange={(e) => { setName(e.target.value) }} error={fieldError('name')} required />
        </div>
        <ul className="flex flex-wrap gap-3" aria-label={t('admin.operators.roles')}>
          {(roles.data?.data ?? []).filter((role) => role.is_staff_role).map((role) => (
            <li key={role.name}>
              <label className="flex items-center gap-1.5 text-sm">
                <input
                  type="checkbox"
                  checked={selected.includes(role.name)}
                  onChange={(e) => {
                    setSelected((held) => (e.target.checked
                      ? [...held, role.name]
                      : held.filter((n) => n !== role.name)))
                  }}
                />
                {role.label}
              </label>
            </li>
          ))}
        </ul>
        {/* No password is chosen here or anywhere: the person takes the
            account over through the one-time link. */}
        <Alert tone="info">{t('admin.operators.noPassword')}</Alert>
        {failure !== null && failure.fields === null ? <Alert tone="error">{failure.message}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onDone}>{t('common.cancel')}</Button>
          <Button type="submit" loading={invite.isPending}>{t('admin.operators.invite')}</Button>
        </div>
      </form>
    </Card>
  )
}
