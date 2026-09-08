import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';

const adminUiDir = fileURLToPath(new URL('.', import.meta.url));

// CSP-safe production asset build: the bundle is emitted under
// public/admin/assets/cms-akira-builder and served as static 'self' assets.
// The prebuilt bundle performs no runtime eval (it needs no script CSP eval
// allowance). Source maps are disabled so the committed bundle has no data:-URL
// residues under a strict CSP.
export default defineConfig({
  plugins: [react()],
  base: '/admin/assets/cms-akira-builder/',
  build: {
    outDir: resolve(adminUiDir, '../../../../public/admin/assets/cms-akira-builder'),
    emptyOutDir: true,
    sourcemap: false,
    target: 'es2020',
    minify: 'esbuild',
    // Deterministic single-entry output so the authenticated shell page can
    // reference the JS bundle directly and glob the emitted stylesheet.
    rollupOptions: {
      output: {
        entryFileNames: 'assets/cms-akira-builder-admin.js',
        chunkFileNames: 'assets/[name].js',
        assetFileNames: 'assets/[name][extname]',
      },
    },
  },
});
