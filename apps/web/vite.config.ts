import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: Number(process.env.WEB_PORT ?? 5173),
    // The API lives on a different origin and authenticates with cookies, so
    // requests are proxied in development to keep them same-site. Without this
    // the browser would drop the session cookie on every request.
    //
    // Matched as anchored regular expressions rather than as the plain prefixes
    // '/api' and '/sanctum'. A plain prefix also matches every portal route that
    // merely starts with those letters: /api-tokens was being forwarded to the
    // control plane, which answered 404, so the API tokens screen could be
    // reached by clicking a link but not by opening or reloading its URL. Any
    // reverse proxy in front of this application needs the same care — an nginx
    // `location /api` is a prefix match too.
    proxy: {
      '^/api/': {
        target: process.env.VITE_API_URL ?? 'http://localhost:8000',
        changeOrigin: false,
      },
      '^/sanctum/': {
        target: process.env.VITE_API_URL ?? 'http://localhost:8000',
        changeOrigin: false,
      },
    },
  },
})
