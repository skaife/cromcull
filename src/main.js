import { createApp } from 'vue'
import { register, getLanguage } from '@nextcloud/l10n'
import App from './App.vue'

import deTranslations from '../l10n/de.json'

const lang = getLanguage()
if (lang?.startsWith('de')) {
	register('cromcull', deTranslations.translations)
}

document.addEventListener('DOMContentLoaded', () => {
	const el = document.getElementById('cromcull')
	if (el) {
		const app = createApp(App)
		app.mount(el)
	}
})
