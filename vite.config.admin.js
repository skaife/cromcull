import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { join } from 'path'

const appName = 'cromcull'

export default defineConfig({
	plugins: [vue()],
	resolve: {
		alias: {
			path: 'path-browserify',
		},
	},
	build: {
		outDir: 'js',
		emptyOutDir: false,
		rollupOptions: {
			input: {
				admin: join(__dirname, 'src', 'admin.js'),
			},
			output: {
				entryFileNames: `${appName}-[name].js`,
				chunkFileNames: `${appName}-[name].js`,
				assetFileNames: `${appName}-admin-[name].[ext]`,
				format: 'iife',
				inlineDynamicImports: true,
			},
		},
		cssCodeSplit: false,
		minify: true,
	},
})
