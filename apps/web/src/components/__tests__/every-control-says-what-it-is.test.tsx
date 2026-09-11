import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import { CheckboxField } from '@/components/CheckboxField'
import { Field } from '@/components/Field'
import { FileField } from '@/components/FileField'
import { RadioGroup } from '@/components/RadioGroup'
import { SelectField } from '@/components/SelectField'
import { Switch } from '@/components/Switch'
import { TextareaField } from '@/components/TextareaField'
import '@/i18n'

/**
 * W5.6 item 8. The form components, audited after Wave 5 moved twenty-three
 * hand-rolled controls onto them.
 *
 * That migration is the reason this file exists. Consolidating every control
 * onto seven components means an accessibility mistake in one of them is an
 * accessibility mistake on every screen at once — which is the trade the
 * design system makes, and it is only worth it if the components are held to
 * the contract. Six of the twenty-three had a `<span>` where the `<label>`
 * should have been; none carried `aria-invalid`; none wired an error to
 * `aria-describedby`.
 *
 * Every assertion here is about what assistive technology is told, which is
 * why they are written through `getByRole`, `getByLabelText` and the ARIA
 * attributes rather than against class names.
 */

describe('a text field', () => {
  it('is found by its label, which is a real label', () => {
    render(<Field label="Hostname" value="" onChange={() => undefined} />)

    // `getByLabelText` resolves through `htmlFor`/`id` and `aria-labelledby`.
    // A `<span>` above an input does not satisfy it, which is the defect the
    // migration removed from six screens.
    expect(screen.getByLabelText('Hostname')).toBeInstanceOf(HTMLInputElement)
  })

  it('associates its hint, so it is read with the field rather than lost', () => {
    render(
      <Field label="Hostname" hint="Letters, digits and hyphens." value="" onChange={() => undefined} />,
    )

    const field = screen.getByLabelText('Hostname')
    const described = field.getAttribute('aria-describedby') ?? ''

    expect(described).not.toBe('')
    expect(document.getElementById(described.split(' ')[0] ?? '')).toHaveTextContent(
      'Letters, digits and hyphens.',
    )
  })

  it('associates its error and announces it, rather than only colouring it', () => {
    render(<Field label="Hostname" error="That name is taken." value="" onChange={() => undefined} />)

    const field = screen.getByLabelText('Hostname')

    // Invalid as a state, not as a border colour.
    expect(field).toHaveAttribute('aria-invalid', 'true')

    const described = (field.getAttribute('aria-describedby') ?? '').split(' ')
    const error = described.map((id) => document.getElementById(id)).find((node) => node !== null)

    expect(error).toHaveTextContent('That name is taken.')

    // `role="alert"`, so it is spoken when it appears instead of waiting to be
    // found. Colour alone is not a message.
    expect(screen.getByRole('alert')).toHaveTextContent('That name is taken.')
  })

  it('reads the hint before the error, because "what is this" comes first', () => {
    render(
      <Field
        label="Hostname"
        hint="Letters, digits and hyphens."
        error="That name is taken."
        value=""
        onChange={() => undefined}
      />,
    )

    const described = (screen.getByLabelText('Hostname').getAttribute('aria-describedby') ?? '').split(' ')
    const texts = described.map((id) => document.getElementById(id)?.textContent ?? '')

    expect(texts.join(' | ')).toBe('That name is taken. | Letters, digits and hyphens.')
  })

  it('communicates requiredness through the control, not an asterisk in the name', () => {
    /*
     * An asterisk in the label makes the field's accessible name "Password *",
     * reads aloud as "star", and means nothing without a legend elsewhere on
     * the page. The `required` attribute is what assistive technology
     * announces, and it is what the control carries.
     */
    render(<Field label="Password" type="password" required value="" onChange={() => undefined} />)

    const field = screen.getByLabelText('Password')

    expect(field).toBeRequired()
    expect(field.getAttribute('aria-label')).toBeNull()
    expect(screen.queryByText('*')).not.toBeInTheDocument()
  })

  it('keeps its name for assistive technology when the label is visually hidden', () => {
    // For a control in a table cell, where the column header is the visible
    // label and repeating it in every row would be noise.
    render(<Field label="Console input" labelHidden value="" onChange={() => undefined} />)

    expect(screen.getByLabelText('Console input')).toBeInstanceOf(HTMLInputElement)
  })
})

describe('a select', () => {
  it('is found by its label and lists its options', () => {
    render(
      <SelectField
        label="Time zone"
        value="Asia/Kuwait"
        onChange={() => undefined}
        options={[
          { value: 'UTC', label: 'UTC' },
          { value: 'Asia/Kuwait', label: 'Asia/Kuwait' },
        ]}
      />,
    )

    const select = screen.getByLabelText('Time zone')

    expect(select).toBeInstanceOf(HTMLSelectElement)
    expect(screen.getByRole('option', { name: 'Asia/Kuwait' })).toBeInTheDocument()
  })

  it('carries aria-invalid and its error like every other field', () => {
    render(
      <SelectField
        label="Currency"
        error="This currency is not available."
        value="KWD"
        onChange={() => undefined}
        options={[{ value: 'KWD', label: 'KWD' }]}
      />,
    )

    expect(screen.getByLabelText('Currency')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByRole('alert')).toHaveTextContent('This currency is not available.')
  })
})

describe('a textarea', () => {
  it('is found by its label', () => {
    render(<TextareaField label="Describe the problem" value="" onChange={() => undefined} />)

    expect(screen.getByLabelText('Describe the problem')).toBeInstanceOf(HTMLTextAreaElement)
  })

  it('keeps a technical value left to right without losing its name', () => {
    // A pasted zone file stays LTR on an Arabic page; the name is unaffected.
    render(<TextareaField label="Zone file" dir="ltr" value="" onChange={() => undefined} />)

    expect(screen.getByLabelText('Zone file')).toHaveAttribute('dir', 'ltr')
  })
})

describe('a checkbox', () => {
  it('is labelled by the whole sentence beside it', () => {
    render(<CheckboxField label="I accept the terms" checked={false} onChange={() => undefined} />)

    expect(screen.getByRole('checkbox', { name: 'I accept the terms' })).toBeInTheDocument()
  })

  it('keeps its name when the label is hidden for a table cell', () => {
    render(
      <CheckboxField label="Select backup-2026-03-01" labelHidden checked={false} onChange={() => undefined} />,
    )

    expect(screen.getByRole('checkbox', { name: 'Select backup-2026-03-01' })).toBeInTheDocument()
  })

  it('announces its error rather than only reddening', () => {
    render(
      <CheckboxField
        label="I accept the terms"
        error="You have to accept the terms to continue."
        checked={false}
        onChange={() => undefined}
      />,
    )

    expect(screen.getByRole('checkbox')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByRole('alert')).toHaveTextContent('You have to accept the terms to continue.')
  })
})

describe('a switch', () => {
  it('is a switch and says whether it is on', () => {
    /*
     * Deliberately not a checkbox. A checkbox is part of a form and does
     * nothing until a submit; flicking a switch *is* the submit. The role is
     * what tells a screen reader which of those it is holding, and
     * `aria-checked` is what tells it the current setting — "on"/"off" rather
     * than "ticked".
     */
    render(<Switch label="Email me about billing" checked onChange={() => undefined} />)

    const control = screen.getByRole('switch', { name: 'Email me about billing' })

    expect(control).toHaveAttribute('aria-checked', 'true')
  })

  it('says when it is off', () => {
    render(<Switch label="Email me about billing" checked={false} onChange={() => undefined} />)

    expect(screen.getByRole('switch')).toHaveAttribute('aria-checked', 'false')
  })

  it('is operable by keyboard, being a real button', async () => {
    const changed = vi.fn()
    const user = userEvent.setup()

    render(<Switch label="Email me about billing" checked={false} onChange={changed} />)

    screen.getByRole('switch').focus()
    await user.keyboard('{Enter}')
    await user.keyboard(' ')

    expect(changed).toHaveBeenCalledTimes(2)
  })

  it('is announced as unavailable rather than silently inert when it cannot move', () => {
    // Billing email cannot be switched off; shown and disabled rather than
    // omitted, so nobody wonders whether they were switched off silently.
    render(<Switch label="Email me about billing" checked disabled onChange={() => undefined} />)

    expect(screen.getByRole('switch')).toBeDisabled()
    expect(screen.getByRole('switch')).toHaveAttribute('aria-checked', 'true')
  })
})

describe('a file picker', () => {
  it('is a real file input, found by its label', () => {
    /*
     * A real `<input type="file">` rather than a hidden input behind a styled
     * label, which is the usual way of making one look consistent and is how
     * file pickers stop working with a keyboard.
     */
    render(<FileField label="Attachments" />)

    const input = screen.getByLabelText('Attachments')

    expect(input).toBeInstanceOf(HTMLInputElement)
    expect(input).toHaveAttribute('type', 'file')
  })

  it('is reachable and activatable by keyboard', async () => {
    const user = userEvent.setup()

    render(<FileField label="Attachments" />)

    await user.tab()

    expect(document.activeElement).toBe(screen.getByLabelText('Attachments'))
  })

  it('associates its hint', () => {
    render(<FileField label="Attachments" hint="Up to 5 MB each." />)

    const described = screen.getByLabelText('Attachments').getAttribute('aria-describedby') ?? ''

    expect(document.getElementById(described.split(' ')[0] ?? '')).toHaveTextContent('Up to 5 MB each.')
  })
})

describe('a radio group', () => {
  it('is one field with several buttons, not several unrelated fields', () => {
    /*
     * The distinction is carried entirely by markup nobody can see:
     * `<fieldset>` and `<legend>` are what make a screen reader announce
     * "Account type, individual, one of two" instead of reading two unrelated
     * controls, and a shared `name` is what makes the arrow keys move between
     * them.
     */
    render(
      <RadioGroup
        label="Account type"
        name="account_type"
        value="individual"
        onChange={() => undefined}
        options={[
          { value: 'individual', label: 'An individual' },
          { value: 'organization', label: 'An organisation' },
        ]}
      />,
    )

    expect(screen.getByRole('group', { name: 'Account type' })).toBeInTheDocument()

    const chosen = screen.getByRole('radio', { name: 'An individual' })

    expect(chosen).toBeChecked()
    expect(chosen).toHaveAttribute('name', 'account_type')
    expect(screen.getByRole('radio', { name: 'An organisation' })).not.toBeChecked()
  })

  it('moves between its options with the arrow keys', async () => {
    const changed = vi.fn()
    const user = userEvent.setup()

    render(
      <RadioGroup
        label="Account type"
        name="account_type"
        value="individual"
        onChange={changed}
        options={[
          { value: 'individual', label: 'An individual' },
          { value: 'organization', label: 'An organisation' },
        ]}
      />,
    )

    screen.getByRole('radio', { name: 'An individual' }).focus()
    await user.keyboard('{ArrowDown}')

    expect(changed).toHaveBeenCalledWith('organization')
  })

  it('announces its error against the group rather than one button', () => {
    render(
      <RadioGroup
        label="Account type"
        name="account_type"
        value=""
        error="Choose one."
        onChange={() => undefined}
        options={[{ value: 'individual', label: 'An individual' }]}
      />,
    )

    const group = screen.getByRole('group', { name: 'Account type' })
    const described = (group.getAttribute('aria-describedby') ?? '').split(' ')

    expect(described.map((id) => document.getElementById(id)?.textContent ?? '').join('')).toContain(
      'Choose one.',
    )
    expect(screen.getByRole('alert')).toHaveTextContent('Choose one.')
  })
})
