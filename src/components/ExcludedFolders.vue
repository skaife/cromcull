<template>
	<div class="excluded-folders">
		<div class="excluded-folders__header">
			<h3>{{ t('cromcull', 'Excluded Folders') }}</h3>
			<NcButton type="secondary" @click="pickFolder">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('cromcull', 'Exclude folder') }}
			</NcButton>
		</div>

		<p class="excluded-folders__hint">
			{{ t('cromcull', 'Files in excluded folders are skipped during duplicate scanning.') }}
		</p>

		<div v-if="folders.length > 0" class="excluded-folders__list">
			<div v-for="folder in folders"
				:key="folder.path"
				class="excluded-folders__item">
				<span class="excluded-folders__path">{{ folder.path }}</span>
				<NcButton type="tertiary-no-background"
					:aria-label="t('cromcull', 'Remove exclusion')"
					@click="removeExclusion(folder.path)">
					<template #icon>
						<CloseIcon :size="20" />
					</template>
				</NcButton>
			</div>
		</div>
		<p v-else class="excluded-folders__hint">
			{{ t('cromcull', 'No folders excluded.') }}
		</p>

		<div v-if="adminFolders.length > 0" class="excluded-folders__admin">
			<h4>{{ t('cromcull', 'Excluded by administrator') }}</h4>
			<div v-for="folder in adminFolders"
				:key="'admin-' + folder.path"
				class="excluded-folders__item excluded-folders__item--readonly">
				<ShieldLockIcon :size="16" class="excluded-folders__lock-icon" />
				<span class="excluded-folders__path">{{ folder.path }}</span>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { ref, onMounted } from 'vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import ShieldLockIcon from 'vue-material-design-icons/ShieldLock.vue'

export default {
	name: 'ExcludedFolders',
	components: { NcButton, CloseIcon, PlusIcon, ShieldLockIcon },
	setup() {
		const folders = ref([])
		const adminFolders = ref([])

		onMounted(() => loadFolders())

		async function loadFolders() {
			try {
				const res = await axios.get(generateUrl('/apps/cromcull/ignore/user'))
				folders.value = res.data.folders
				adminFolders.value = res.data.adminFolders || []
			} catch (e) {
				showError(t('cromcull', 'Failed to load excluded folders'))
			}
		}

		async function pickFolder() {
			try {
				const path = await new Promise((resolve, reject) => {
					OC.dialogs.filepicker(
						t('cromcull', 'Select folder to exclude'),
						(selectedPath) => resolve(selectedPath),
						false,
						['httpd/unix-directory'],
						true,
						1,
					)
				})
				if (!path) return

				await axios.post(generateUrl('/apps/cromcull/ignore/user'), { path })
				await loadFolders()
			} catch (e) {
				showError(t('cromcull', 'Failed to exclude folder'))
			}
		}

		async function removeExclusion(path) {
			try {
				await axios.delete(generateUrl('/apps/cromcull/ignore/user'), { data: { path } })
				await loadFolders()
			} catch (e) {
				showError(t('cromcull', 'Failed to remove exclusion'))
			}
		}

		return { folders, adminFolders, pickFolder, removeExclusion, t }
	},
}
</script>

<style scoped>
.excluded-folders {
	border-top: 1px solid var(--color-border);
	padding-top: 16px;
	margin-top: 8px;
}

.excluded-folders__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 4px;
}

.excluded-folders__header h3 {
	margin: 0;
	font-size: 1.1em;
	font-weight: 600;
}

.excluded-folders__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0 0 8px 0;
}

.excluded-folders__list {
	margin-bottom: 8px;
}

.excluded-folders__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border-dark);
}

.excluded-folders__item--readonly {
	color: var(--color-text-maxcontrast);
}

.excluded-folders__lock-icon {
	flex-shrink: 0;
}

.excluded-folders__path {
	flex: 1;
	font-family: var(--font-monospace, monospace);
	font-size: 0.9em;
}

.excluded-folders__admin {
	margin-top: 12px;
}

.excluded-folders__admin h4 {
	margin: 0 0 4px 0;
	font-size: 0.95em;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}
</style>
