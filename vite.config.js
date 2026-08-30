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
		rollupOptions: {
			input: {
				main: join(__dirname, 'src', 'main.js'),
			},
			output: {
				entryFileNames: `${appName}-[name].js`,
				chunkFileNames: `${appName}-[name].js`,
				assetFileNames: `${appName}-[name].[ext]`,
				format: 'iife',
				inlineDynamicImports: true,
			},
		},
		cssCodeSplit: false,
		minify: true,
	},
})
