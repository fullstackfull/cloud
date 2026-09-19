import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { Loading } from '@/components/Loading'
import i18n from '@/i18n'
import ar from '@/i18n/locales/ar.json'
import en from '@/i18n/locales/en.json'

describe('Loading', () => {
  it('is a status region with the translated sentence', async () => {
    render(<Loading />)
    const status = screen.getByRole('status')
    expect(status).toHaveTextContent(en.common.loading)

    await i18n.changeLanguage('ar')
    try {
      render(<Loading />)
      expect(screen.getAllByRole('status').at(-1)).toHaveTextContent(ar.common.loading)
    } finally {
      await i18n.changeLanguage('en')
    }
  })
})
