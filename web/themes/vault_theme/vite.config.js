import { defineConfig } from 'vite';

export default defineConfig({
  server: {
    // 1. Allow the server to be reachable from outside localhost
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    // 2. Open the gates for CORS
    cors: {
      origin: '*', // This allows any site (like your DDEV site) to request assets
      methods: ['GET', 'OPTIONS'],
      allowedHeaders: ['Content-Type', 'Authorization'],
    },
    // 3. Ensure the browser knows where to find the HMR socket
    hmr: {
      host: 'localhost',
    },
  },

  // 4. ADD THIS BUILD SECTION to generate the manifest.json
  build: {
    // Output directory (defaults to 'dist')
    outDir: 'dist',
    // THIS IS THE CRITICAL SETTING FOR DRUPAL
    manifest: true,
    // When using Vite with a backend like Drupal, you usually want to clear the folder on build
    emptyOutDir: true,
    // Specify your entry points (CSS/JS). Update these paths to match your actual files!
    rollupOptions: {
      input: {
        main: './src/js/main.js', // <-- Change this to your actual JS entry file
        style: './src/scss/main.scss' // <-- Change this to your actual CSS entry file
      }
    }
  }
});
