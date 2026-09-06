import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'

import { App } from '@/app/App'
import { applyLocale, detectLocale } from '@/i18n'
import '@/i18n'
import '@/styles/index.css'

// Set language and direction before the first paint, so a right-to-left
// customer never sees a flash of left-to-right layout.
applyLocale(detectLocale())

const container = document.getElementById('root')
if (container === null) {
  throw new Error('Root container is missing from index.html')
}

createRoot(container).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
