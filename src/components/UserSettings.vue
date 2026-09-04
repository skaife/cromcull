<template>
	<div class="user-settings">
		<div class="user-settings__header" @click="expanded = !expanded">
			<CogIcon :size="20" />
			<h3>{{ t('cromcull', 'Scan Filters') }}</h3>
			<ChevronDownIcon v-if="!expanded" :size="20" />
			<ChevronUpIcon v-else :size="20" />
		</div>

		<div v-if="expanded" class="user-settings__body">
			<p class="user-settings__hint">
				{{ t('cromcull', 'Set stricter filters for your scans. These add to the filters set by your administrator.') }}
			</p>

			<div class="user-settings__row">
				<label>{{ t('cromcull', 'Minimum file size') }}</label>
				<div class="user-settings__size-input">
					<div class="user-settings__number-wrap">
						<NcTextField :model-value="minSizeValue"
							type="number"
							:placeholder="minPlaceholder"
							@update:model-value="v => minSizeValue = v" />
					</div>
					<div class="user-settings__unit-wrap">
						<NcSelect v-model="minSizeUnit"
							:options="unitOptions"
							:clearable="false"
							:searchable="false" />
					</div>
				</div>
				<p v-if="adminMinLabel" class="user-settings__admin-hint">
					{{ t('cromcull', 'Administrator minimum: {size}', { size: adminMinLabel }) }}
				</p>
			</div>

			<div class="user-settings__row">
				<label>{{ t('cromcull', 'Maximum file size') }}</label>
				<div class="user-settings__size-input">
					<div class="user-settings__number-wrap">
						<NcTextField :model-value="maxSizeValue"
							type="number"
							:placeholder="maxPlaceholder"
							@update:model-value="v => maxSizeValue = v" />
					</div>
					<div class="user-settings__unit-wrap">
						<NcSelect v-model="maxSizeUnit"
							:options="unitOptions"
							:clearable="false"
							:searchable="false" />
					</div>
				</div>
				<p v-if="adminMaxLabel" class="user-settings__admin-hint">
					{{ t('cromcull', 'Administrator maximum: {size}', { size: adminMaxLabel }) }}
				</p>
			</div>

			<div class="user-settings__row">
				<label>{{ t('cromcull', 'Ignored extensions') }}</label>
				<NcTextField :model-value="ignoredExtensions"
					:placeholder="t('cromcull', 'e.g. iso, exe, bak')"
					@update:model-value="v => ignoredExtensions = v" />
				<p v-if="adminExtsLabel" class="user-settings__admin-hint">
					{{ t('cromcull', 'Administrator exclusions: {extensions}', { extensions: adminExtsLabel }) }}
				</p>
			</div>

			<div class="user-settings__actions">
				<NcButton type="primary"
					:disabled="saving"
					@click="save">
					{{ saving ? t('cromcull', 'Saving...') : t('cromcull', 'Save Filters') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcSelect } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { ref, computed, onMounted } from 'vue'
import { formatSize } from '../utils/formatSize.js'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUpIcon from 'vue-material-design-icons/ChevronUp.vue'

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
	name: 'UserSettings',
	components: { NcButton, NcTextField, NcSelect, CogIcon, ChevronDownIcon, ChevronUpIcon },
	emits: ['saved'],
	setup(props, { emit }) {
		const expanded = ref(false)
		const saving = ref(false)

		const minSizeValue = ref('')
		const minSizeUnit = ref(UNITS[1])
		const maxSizeValue = ref('')
		const maxSizeUnit = ref(UNITS[1])
		const ignoredExtensions = ref('')

		const adminConfig = ref({ min_size: 0, max_size: 0, ignored_extensions: '' })

		const unitOptions = UNITS

		const adminMinLabel = computed(() => {
			return adminConfig.value.min_size > 0 ? formatSize(adminConfig.value.min_size) : ''
		})

		const adminMaxLabel = computed(() => {
			return adminConfig.value.max_size > 0 ? formatSize(adminConfig.value.max_size) : ''
		})

		const adminExtsLabel = computed(() => {
			return adminConfig.value.ignored_extensions || ''
		})

		const minPlaceholder = computed(() => {
			if (adminConfig.value.min_size > 0) {
				return formatSize(adminConfig.value.min_size)
			}
			return t('cromcull', 'No minimum')
		})

		const maxPlaceholder = computed(() => {
			if (adminConfig.value.max_size > 0) {
				return formatSize(adminConfig.value.max_size)
			}
			return t('cromcull', 'No maximum')
		})

		onMounted(async () => {
			try {
				const res = await axios.get(generateUrl('/apps/cromcull/user-config'))
				const data = res.data
				adminConfig.value = data.admin

				const min = bytesToUnitValue(data.user.min_size)
				minSizeValue.value = min.value
				minSizeUnit.value = min.unit
				const max = bytesToUnitValue(data.user.max_size)
				maxSizeValue.value = max.value
				maxSizeUnit.value = max.unit
				ignoredExtensions.value = data.user.ignored_extensions
			} catch (e) {
				// silent load failure
			}
		})

		async function save() {
			saving.value = true
			try {
				const minBytes = unitValueToBytes(minSizeValue.value, minSizeUnit.value)
				const maxBytes = unitValueToBytes(maxSizeValue.value, maxSizeUnit.value)
				const res = await axios.post(generateUrl('/apps/cromcull/user-config'), {
					min_size: String(minBytes),
					max_size: String(maxBytes),
					ignored_extensions: ignoredExtensions.value,
				})
				const data = res.data
				adminConfig.value = data.admin

				const min = bytesToUnitValue(data.user.min_size)
				minSizeValue.value = min.value
				minSizeUnit.value = min.unit
				const max = bytesToUnitValue(data.user.max_size)
				maxSizeValue.value = max.value
				maxSizeUnit.value = max.unit
				ignoredExtensions.value = data.user.ignored_extensions

				showSuccess(t('cromcull', 'Filters saved'))
				emit('saved')
			} catch (e) {
				if (e.response?.status === 400) {
					showError(e.response.data.error)
				} else {
					showError(t('cromcull', 'Failed to save filters'))
				}
			} finally {
				saving.value = false
			}
		}

		return {
			expanded, saving,
			minSizeValue, minSizeUnit,
			maxSizeValue, maxSizeUnit,
			ignoredExtensions,
			unitOptions,
			adminMinLabel, adminMaxLabel, adminExtsLabel,
			minPlaceholder, maxPlaceholder,
			save, t,
		}
	},
}
</script>

<style scoped>
.user-settings {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	margin-bottom: 16px;
}

.user-settings__header {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px 16px;
	cursor: pointer;
	user-select: none;
}

.user-settings__header:hover {
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.user-settings__header h3 {
	margin: 0;
	flex: 1;
	font-size: 1em;
	font-weight: 600;
}

.user-settings__body {
	padding: 0 16px 16px;
}

.user-settings__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0 0 12px 0;
}

.user-settings__row {
	margin-bottom: 12px;
}

.user-settings__row > label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
	font-size: 0.95em;
}

.user-settings__size-input {
	display: flex;
	gap: 8px;
	align-items: flex-start;
}

.user-settings__number-wrap {
	width: 120px;
	flex: 0 0 120px;
}

.user-settings__unit-wrap {
	width: 80px;
	flex: 0 0 80px;
}

.user-settings__admin-hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
	margin: 2px 0 0 0;
	font-style: italic;
}

.user-settings__actions {
	margin-top: 12px;
}
</style>
