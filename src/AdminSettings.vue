<template>
	<div class="cromcull-admin">
		<h2>CromCull</h2>

		<div class="cromcull-admin__section">
			<h3>{{ t('cromcull', 'Scan Filters') }}</h3>
			<p class="cromcull-admin__hint">
				{{ t('cromcull', 'These filters apply when scanning for duplicates. Files outside these limits are skipped before any hashing.') }}
			</p>

			<div class="cromcull-admin__row">
				<label>{{ t('cromcull', 'Minimum file size') }}</label>
				<div class="cromcull-admin__size-input">
					<div class="cromcull-admin__number-wrap">
						<NcTextField :model-value="minSizeValue"
							type="number"
							:placeholder="t('cromcull', 'No minimum')"
							@update:model-value="v => minSizeValue = v" />
					</div>
					<div class="cromcull-admin__unit-wrap">
						<NcSelect v-model="minSizeUnit"
							:options="unitOptions"
							:clearable="false"
							:searchable="false" />
					</div>
				</div>
			</div>

			<div class="cromcull-admin__row">
				<label>{{ t('cromcull', 'Maximum file size') }}</label>
				<div class="cromcull-admin__size-input">
					<div class="cromcull-admin__number-wrap">
						<NcTextField :model-value="maxSizeValue"
							type="number"
							:placeholder="t('cromcull', 'No maximum')"
							@update:model-value="v => maxSizeValue = v" />
					</div>
					<div class="cromcull-admin__unit-wrap">
						<NcSelect v-model="maxSizeUnit"
							:options="unitOptions"
							:clearable="false"
							:searchable="false" />
					</div>
				</div>
			</div>

			<div class="cromcull-admin__row">
				<label>{{ t('cromcull', 'Ignored extensions') }}</label>
				<NcTextField :model-value="ignoredExtensions"
					:placeholder="t('cromcull', 'e.g. tmp, log, bak')"
					@update:model-value="v => ignoredExtensions = v" />
				<p class="cromcull-admin__hint">
					{{ t('cromcull', 'Comma-separated list of file extensions to skip during scanning. Leading dots are stripped automatically.') }}
				</p>
			</div>

			<div class="cromcull-admin__row">
				<label>{{ t('cromcull', 'Scan chunk budget') }}</label>
				<div class="cromcull-admin__size-input">
					<div class="cromcull-admin__number-wrap">
						<NcTextField :model-value="chunkBudgetValue"
							type="number"
							:placeholder="'100'"
							@update:model-value="v => chunkBudgetValue = v" />
					</div>
					<div class="cromcull-admin__unit-wrap">
						<NcSelect v-model="chunkBudgetUnit"
							:options="unitOptions"
							:clearable="false"
							:searchable="false" />
					</div>
				</div>
				<p class="cromcull-admin__hint">
					{{ t('cromcull', 'Target size per scan batch. Larger values mean fewer requests but longer pauses between progress updates.') }}
				</p>
			</div>
		</div>

		<div class="cromcull-admin__actions">
			<NcButton type="primary"
				:disabled="saving"
				@click="save">
				{{ saving ? t('cromcull', 'Saving...') : t('cromcull', 'Save') }}
			</NcButton>
		</div>

		<div class="cromcull-admin__section">
			<h3>{{ t('cromcull', 'Hash Cache') }}</h3>
			<p class="cromcull-admin__hint">
				{{ t('cromcull', 'Cached file hashes speed up rescans by skipping files that have not changed. The cache is automatically maintained during scans.') }}
			</p>
			<div class="cromcull-admin__cache-row">
				<span v-if="cacheCount !== null">
					{{ t('cromcull', '{count} cached entries', { count: cacheCount }) }}
				</span>
				<NcButton type="error"
					:disabled="clearingCache"
					@click="clearCache">
					{{ clearingCache ? t('cromcull', 'Clearing...') : t('cromcull', 'Clear entire cache') }}
				</NcButton>
			</div>
			<p class="cromcull-admin__hint">
				{{ t('cromcull', '*** The first scan must read and hash every file, which can take a long time on large storage — consider running it overnight. After that, the cache makes repeat scans much faster by skipping unchanged files.') }}
			</p>
		</div>

		<div class="cromcull-admin__section">
			<h3>{{ t('cromcull', 'Excluded Folders') }}</h3>
			<p class="cromcull-admin__hint">
				{{ t('cromcull', 'Folders excluded from duplicate scanning for all users. A .cromcull_ignore file is placed in each excluded folder.') }}
			</p>

			<div v-if="excludedFolders.length > 0" class="cromcull-admin__folder-list">
				<div v-for="folder in excludedFolders"
					:key="folder.path"
					class="cromcull-admin__folder-item">
					<span class="cromcull-admin__folder-path">{{ folder.path }}</span>
					<span v-if="folder.invalid" class="cromcull-admin__folder-invalid">
						{{ t('cromcull', 'Invalid file — not currently enforced') }}
					</span>
					<NcButton type="tertiary-no-background"
						:aria-label="t('cromcull', 'Remove exclusion')"
						@click="removeExclusion(folder.path)">
						<template #icon>
							<CloseIcon :size="20" />
						</template>
					</NcButton>
				</div>
			</div>
			<p v-else class="cromcull-admin__hint">
				{{ t('cromcull', 'No folders are currently excluded.') }}
			</p>

			<NcButton @click="pickFolder">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('cromcull', 'Exclude folder') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcSelect } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { ref, onMounted } from 'vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'

const UNITS = [
	{ id: 'B', label: 'B', multiplier: 1 },
	{ id: 'KB', label: 'KB', multiplier: 1024 },
	{ id: 'MB', label: 'MB', multiplier: 1024 * 1024 },
	{ id: 'GB', label: 'GB', multiplier: 1024 * 1024 * 1024 },
]

function bytesToUnitValue(bytes) {
	bytes = parseInt(bytes, 10) || 0
	if (bytes === 0) return { value: '', unit: UNITS[1] }
	for (let i = UNITS.length - 1; i >= 1; i--) {
		if (bytes >= UNITS[i].multiplier && bytes % UNITS[i].multiplier === 0) {
			return { value: String(bytes / UNITS[i].multiplier), unit: UNITS[i] }
		}
	}
	return { value: String(bytes), unit: UNITS[0] }
}

function unitValueToBytes(value, unit) {
	const num = parseInt(value, 10) || 0
	return num * (unit?.multiplier || 1)
}

export default {
	name: 'AdminSettings',
	components: { NcButton, NcTextField, NcSelect, CloseIcon, PlusIcon },
	setup() {
		const minSizeValue = ref('')
		const minSizeUnit = ref(UNITS[1])
		const maxSizeValue = ref('')
		const maxSizeUnit = ref(UNITS[1])
		const ignoredExtensions = ref('')
		const chunkBudgetValue = ref('100')
		const chunkBudgetUnit = ref(UNITS[2])
		const saving = ref(false)
		const excludedFolders = ref([])
		const cacheCount = ref(null)
		const clearingCache = ref(false)

		const unitOptions = UNITS

		onMounted(async () => {
			try {
				const res = await axios.get(generateUrl('/apps/cromcull/scan-config'))
				const data = res.data
				const min = bytesToUnitValue(data.min_size)
				minSizeValue.value = min.value
				minSizeUnit.value = min.unit
				const max = bytesToUnitValue(data.max_size)
				maxSizeValue.value = max.value
				maxSizeUnit.value = max.unit
				ignoredExtensions.value = data.ignored_extensions
				const budget = bytesToUnitValue(data.chunk_budget)
				chunkBudgetValue.value = budget.value
				chunkBudgetUnit.value = budget.unit
			} catch (e) {
				showError(t('cromcull', 'Failed to load settings'))
			}

			await Promise.all([loadExcludedFolders(), loadCacheStats()])
		})

		async function loadCacheStats() {
			try {
				const res = await axios.get(generateUrl('/apps/cromcull/cache/stats'))
				cacheCount.value = res.data.count
			} catch (e) {
				// silent
			}
		}

		async function clearCache() {
			clearingCache.value = true
			try {
				await axios.post(generateUrl('/apps/cromcull/cache/clear'))
				cacheCount.value = 0
				showSuccess(t('cromcull', 'Hash cache cleared'))
			} catch (e) {
				showError(t('cromcull', 'Failed to clear hash cache'))
			} finally {
				clearingCache.value = false
			}
		}

		async function loadExcludedFolders() {
			try {
				const res = await axios.get(generateUrl('/apps/cromcull/ignore/admin'))
				excludedFolders.value = res.data.folders
			} catch (e) {
				showError(t('cromcull', 'Failed to load excluded folders'))
			}
		}

		async function save() {
			saving.value = true
			try {
				const minBytes = unitValueToBytes(minSizeValue.value, minSizeUnit.value)
				const maxBytes = unitValueToBytes(maxSizeValue.value, maxSizeUnit.value)
				const budgetBytes = unitValueToBytes(chunkBudgetValue.value, chunkBudgetUnit.value)
				const res = await axios.post(generateUrl('/apps/cromcull/scan-config'), {
					min_size: String(minBytes),
					max_size: String(maxBytes),
					ignored_extensions: ignoredExtensions.value,
					chunk_budget: String(budgetBytes),
				})
				const data = res.data
				const min = bytesToUnitValue(data.min_size)
				minSizeValue.value = min.value
				minSizeUnit.value = min.unit
				const max = bytesToUnitValue(data.max_size)
				maxSizeValue.value = max.value
				maxSizeUnit.value = max.unit
				ignoredExtensions.value = data.ignored_extensions
				const budget = bytesToUnitValue(data.chunk_budget)
				chunkBudgetValue.value = budget.value
				chunkBudgetUnit.value = budget.unit
				showSuccess(t('cromcull', 'Settings saved'))
			} catch (e) {
				if (e.response?.status === 400) {
					showError(e.response.data.error)
				} else {
					showError(t('cromcull', 'Failed to save settings'))
				}
			} finally {
				saving.value = false
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

				await axios.post(generateUrl('/apps/cromcull/ignore/admin'), { path })
				await loadExcludedFolders()
			} catch (e) {
				showError(t('cromcull', 'Failed to exclude folder'))
			}
		}

		async function removeExclusion(path) {
			try {
				await axios.delete(generateUrl('/apps/cromcull/ignore/admin'), { data: { path } })
				await loadExcludedFolders()
			} catch (e) {
				showError(t('cromcull', 'Failed to remove exclusion'))
			}
		}

		return {
			minSizeValue, minSizeUnit,
			maxSizeValue, maxSizeUnit,
			ignoredExtensions,
			chunkBudgetValue, chunkBudgetUnit,
			saving, unitOptions,
			excludedFolders,
			cacheCount, clearingCache,
			save, clearCache, pickFolder, removeExclusion, t,
		}
	},
}
</script>

<style scoped>
.cromcull-admin {
	max-width: 700px;
	padding: 24px 32px;
	border: 1px solid var(--color-border);
	border-radius: 12px;
	background: var(--color-main-background);
}

.cromcull-admin h2 {
	font-size: 1.4em;
	font-weight: 700;
	margin: 0 0 16px 0;
}

.cromcull-admin h3 {
	font-size: 1.1em;
	font-weight: 600;
	margin: 0 0 8px 0;
}

.cromcull-admin__section {
	margin-bottom: 24px;
}

.cromcull-admin__row {
	margin-bottom: 16px;
}

.cromcull-admin__row > label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
}

.cromcull-admin__size-input {
	display: flex;
	gap: 8px;
	align-items: flex-start;
}

.cromcull-admin__number-wrap {
	width: 140px;
	flex: 0 0 140px;
}

.cromcull-admin__unit-wrap {
	width: 90px;
	flex: 0 0 90px;
}

.cromcull-admin__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 4px 0 8px 0;
}

.cromcull-admin__actions {
	margin-top: 16px;
	margin-bottom: 24px;
}

.cromcull-admin__folder-list {
	margin-bottom: 12px;
}

.cromcull-admin__folder-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border-dark);
}

.cromcull-admin__folder-path {
	flex: 1;
	font-family: var(--font-monospace, monospace);
	font-size: 0.9em;
}

.cromcull-admin__cache-row {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 8px;
}

.cromcull-admin__folder-invalid {
	color: var(--color-warning);
	font-size: 0.85em;
	font-style: italic;
}
</style>
