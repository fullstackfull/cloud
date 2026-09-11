import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

export interface Column<Row> {
  key: string
  header: ReactNode
  /** Cell content. Kept a render function so a column can show a badge or a button. */
  cell: (row: Row) => ReactNode
  /** Latin-only content — an address, a user agent — that must not mirror in Arabic. */
  ltr?: boolean
}

interface DataTableProps<Row> {
  columns: Array<Column<Row>>
  rows: Row[]
  rowKey: (row: Row) => string
  empty: ReactNode
  caption?: string
}

/**
 * A table that scrolls inside its own box rather than making the page scroll
 * sideways — a session list with a full user-agent string is wider than a phone
 * on any layout, and a horizontally scrolling page is unusable in RTL.
 */
export function DataTable<Row>({ columns, rows, rowKey, empty, caption }: DataTableProps<Row>) {
  const { t } = useTranslation()

  if (rows.length === 0) {
    return <p className="py-8 text-center text-sm text-[var(--text-muted)]">{empty}</p>
  }

  return (
    <div className="-mx-4 overflow-x-auto sm:mx-0">
      <table className="w-full min-w-[36rem] border-collapse text-sm">
        {caption !== undefined ? <caption className="sr-only">{caption}</caption> : null}
        <thead>
          <tr className="border-b border-[var(--border-subtle)] text-start">
            {columns.map((column) => (
              <th
                key={column.key}
                scope="col"
                className="px-4 py-2 text-start text-xs font-medium tracking-wide text-[var(--text-muted)] uppercase"
              >
                {/*
                  An action column has no visible heading, and an empty `<th>`
                  is not the same as a column with nothing to say: it leaves
                  the cells under it associated with nothing, which is what
                  axe reports as an empty table header. The word is supplied
                  for assistive technology and kept off the screen, where the
                  buttons are self-evidently the actions.
                */}
                {column.header === '' ? (
                  <span className="sr-only">{t('common.actions')}</span>
                ) : (
                  column.header
                )}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr
              key={rowKey(row)}
              className="border-b border-[var(--border-subtle)] last:border-0"
            >
              {columns.map((column) => (
                <td
                  key={column.key}
                  dir={column.ltr === true ? 'ltr' : undefined}
                  className="px-4 py-3 align-middle text-[var(--text-primary)]"
                >
                  {column.cell(row)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
