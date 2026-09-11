import { render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { Button } from '@/components/Button'
import { DataTable, type Column } from '@/components/DataTable'
import '@/i18n'

/**
 * W5.6, the tables audit. Every table in the portal is this component, so the
 * semantics are asserted once, here.
 *
 * A table read by eye needs a header row; a table read aloud needs the header
 * *associated* with each cell, because a screen reader announces the column
 * name before the value — "Last active, 3 March" — and without the
 * association it announces the value alone, in a list of forty values.
 *
 * The action column is the interesting case and was a real defect: it has no
 * visible heading, an empty `<th>` associates its cells with nothing, and
 * leaving the `<th>` out entirely makes the row one cell short of its header
 * row, which shifts every announcement by one column.
 */

interface Session {
  id: string
  device: string
  address: string
}

const ROWS: Session[] = [
  { id: '1', device: 'Chrome on macOS', address: '198.51.100.25' },
  { id: '2', device: 'Safari on iPhone', address: '203.0.113.7' },
]

const COLUMNS: Array<Column<Session>> = [
  { key: 'device', header: 'Device', cell: (row) => row.device },
  { key: 'address', header: 'IP address', cell: (row) => row.address, ltr: true },
  // The action column: no visible heading, by design.
  { key: 'actions', header: '', cell: () => <Button>Sign out</Button> },
]

function table(caption?: string) {
  render(
    <DataTable
      columns={COLUMNS}
      rows={ROWS}
      rowKey={(row) => row.id}
      empty="No sessions recorded."
      {...(caption === undefined ? {} : { caption })}
    />,
  )

  return screen.getByRole('table')
}

describe('a table', () => {
  it('gives every column a header that is announced, including the action column', () => {
    const headers = within(table()).getAllByRole('columnheader')

    expect(headers).toHaveLength(COLUMNS.length)

    for (const header of headers) {
      // Associated with the column below it, and not merely bold text.
      expect(header).toHaveAttribute('scope', 'col')

      // Announced: the action column's word is visually hidden, not absent.
      expect(header.textContent.trim(), 'a header with nothing to announce').not.toBe('')
    }

    expect(headers[2]).toHaveTextContent('Actions')
  })

  it('has as many cells in a row as it has headers', () => {
    /*
     * The failure this guards is silent and total: one missing `<th>` shifts
     * every announcement in the table by a column, so a device name is read
     * out as an IP address for every row.
     */
    const rows = within(table()).getAllByRole('row')

    // The header row plus the body.
    expect(rows).toHaveLength(ROWS.length + 1)

    for (const row of rows.slice(1)) {
      expect(within(row).getAllByRole('cell')).toHaveLength(COLUMNS.length)
    }
  })

  it('can be named without putting a heading on the screen', () => {
    // Two tables on one page — sessions and sign-in history — are two tables
    // announced as "table" unless each says which it is.
    const named = table('Active sessions')

    expect(named).toHaveAccessibleName('Active sessions')
    expect(within(named).getByText('Active sessions')).toHaveClass('sr-only')
  })

  it('keeps a Latin technical column left to right', () => {
    /*
     * Not cosmetic. An address that inherits an Arabic page's direction is a
     * different address, and read aloud it is a different number.
     */
    const cells = within(table()).getAllByRole('cell')
    const address = cells.filter((cell) => cell.textContent === '198.51.100.25')

    expect(address).toHaveLength(1)
    expect(address[0]).toHaveAttribute('dir', 'ltr')
  })

  it('says there is nothing rather than drawing an empty grid', () => {
    render(
      <DataTable columns={COLUMNS} rows={[]} rowKey={(row) => row.id} empty="No sessions recorded." />,
    )

    expect(screen.getByText('No sessions recorded.')).toBeInTheDocument()

    // A header row over no rows is a table that looks broken rather than empty.
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })
})
