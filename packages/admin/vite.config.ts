import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";
import tailwindcss from "@tailwindcss/vite";
import path from "node:path";

export default defineConfig({
  plugins: [vue(), tailwindcss()],
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "src"),
      // Use runtime-only ESM bundler to guarantee zero template compilation eval/new Function
      vue: "vue/dist/vue.runtime.esm-bundler.js",
    },
  },
  define: {
    __VUE_OPTIONS_API__: false,
    __VUE_PROD_DEVTOOLS__: false,
    __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: false,
  },
  build: {
    outDir: path.resolve(__dirname, "../core/runtime/admin"),
    emptyOutDir: true,
    cssCodeSplit: false,
    target: "es2022",
    sourcemap: false,
    rollupOptions: {
      input: path.resolve(__dirname, "src/main.ts"),
      output: {
        format: "es",
        inlineDynamicImports: true,
        entryFileNames: "lumora-admin.js",
        assetFileNames: (assetInfo) => {
          const isCss =
            assetInfo.names?.some((n) => n.endsWith(".css")) ||
            assetInfo.name?.endsWith(".css");
          return isCss ? "lumora-admin.css" : "assets/[name][extname]";
        },
      },
    },
  },
});
