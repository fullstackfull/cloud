// Lint configuration for every package under packages/.
//
// It exists so that CI's `npm run lint --workspaces` has something to run in
// each package. Those steps used to pass `--if-present`, and none of the three
// packages defined a script, so a lint error or a type error here exited 0
// having been read by nothing. Now each package declares `lint` and
// `typecheck`, the flag is gone, and a package that loses its scripts fails
// with "Missing script" instead of vanishing from the corpus.
//
// ESLint finds this file by walking up from the package it runs in. It follows
// apps/web/eslint.config.js, whose rules it keeps where they apply outside a
// React application, and like that file it imports @eslint/js through the
// workspace's hoisted node_modules rather than declaring it.
import js from '@eslint/js'
import tseslint from 'typescript-eslint'

export default tseslint.config(
  { ignores: ['**/dist', '**/node_modules', '**/coverage'] },
  {
    extends: [js.configs.recommended, ...tseslint.configs.strictTypeChecked],
    files: ['**/*.{ts,tsx}'],
    languageOptions: {
      ecmaVersion: 2022,
      parserOptions: {
        // Each package's own tsconfig.json, found per file.
        projectService: true,
        tsconfigRootDir: import.meta.dirname,
      },
    },
    rules: {
      '@typescript-eslint/no-floating-promises': 'error',
      '@typescript-eslint/restrict-template-expressions': ['error', { allowNumber: true }],
      '@typescript-eslint/consistent-type-imports': [
        'error',
        { prefer: 'type-imports', fixStyle: 'inline-type-imports' },
      ],
    },
  },
)
