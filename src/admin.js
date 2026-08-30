import { createApp } from 'vue'
import { register, getLanguage } from '@nextcloud/l10n'
import AdminSettings from './AdminSettings.vue'

import deTranslations from '../l10n/de.json'

const lang = getLanguage()
if (lang?.startsWith('de')) {
	register('cromcull', deTranslations.translations)
}

document.addEventListener('DOMContentLoaded', () => {
	const el = document.getElementById('cromcull-admin-settings')
	if (el) {
		const app = createApp(AdminSettings)
		app.mount(el)
	}
})
