import { useTranslation } from 'react-i18next'

import { Button } from '@/components/Button'

interface PaginatorProps {
  page: number
  lastPage: number
  total: number
  onChange: (page: number) => void
}

/**
 * Previous and next, plus where the reader is.
 *
 * Numbered pages are omitted on purpose: with a right-to-left layout a numbered
 * strip has to decide whether "next" is on the left or the right, and every
 * answer is wrong for half the readers. Two labelled buttons and a position
 * read correctly in both directions because the label carries the meaning.
 */
export function Paginator({ page, lastPage, total, onChange }: PaginatorProps) {
  const { t } = useTranslation()

  if (lastPage <= 1) return null

  return (
    <nav
      className="mt-4 flex items-center justify-between gap-3 text-sm"
      aria-label={t('common.pagination')}
    >
      <Button
        variant="secondary"
        size="sm"
        disabled={page <= 1}
        onClick={() => { onChange(page - 1); }}
      >
        {t('common.previous')}
      </Button>

      <span className="text-[var(--text-secondary)]">
        {t('common.pageOf', { page, lastPage, total })}
      </span>

      <Button
        variant="secondary"
        size="sm"
        disabled={page >= lastPage}
        onClick={() => { onChange(page + 1); }}
      >
        {t('common.next')}
      </Button>
    </nav>
  )
}
