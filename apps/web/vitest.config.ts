import { defineConfig, mergeConfig } from 'vitest/config'

import viteConfig from './vite.config'

/*
 * Test configuration is kept out of vite.config.ts on purpose: Vite's own
 * config type has no `test` key, so inlining it there makes the production
 * build config fail typechecking.
 */
export default mergeConfig(
  viteConfig,
  defineConfig({
    test: {
      environment: 'jsdom',
      globals: true,
      setupFiles: ['./src/test-setup.ts'],
      css: false,
    },
  }),
)
