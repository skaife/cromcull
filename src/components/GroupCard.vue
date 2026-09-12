<template>
	<div class="group-card" :class="{ 'group-card--hidden': group.hidden }">
		<div class="group-card__header">
			<div class="group-card__meta">
				<span class="group-card__size">{{ formattedSize }}</span>
				<span class="group-card__count">{{ t('cromcull', '{count} copies', { count: group.members.length }) }}</span>
				<span v-if="group.hidden" class="group-card__hidden-badge">{{ t('cromcull', 'Hidden') }}</span>
			</div>
			<code class="group-card__hash">{{ group.hash }}</code>
			<NcButton type="tertiary"
				:aria-label="t('cromcull', 'Recheck')"
				:disabled="rechecking"
				@click="$emit('recheck')">
				{{ rechecking ? t('cromcull', 'Rechecking...') : t('cromcull', 'Recheck') }}
			</NcButton>
			<NcButton v-if="group.hidden"
				type="tertiary"
				@click="$emit('unhide')">
				{{ t('cromcull', 'Un-Hide') }}
			</NcButton>
			<NcButton v-else
				type="tertiary"
				@click="$emit('hide')">
				{{ t('cromcull', 'Hide') }}
			</NcButton>
		</div>
		<div class="group-card__members">
			<div v-for="member in group.members"
				:key="member.fileid"
				class="group-card__member"
				:class="{ 'group-card__member--protected': member.protected }">
				<NcCheckboxRadioSwitch v-if="!member.protected"
					:model-value="selectedFileIds.has(member.fileid)"
					:disabled="isDisabled(member.fileid)"
					@update:model-value="$emit('toggle', member.fileid)">
					{{ member.path }}
				</NcCheckboxRadioSwitch>
				<span v-else
					class="group-card__protected-label"
					:title="t('cromcull', 'Group folder / shared file — cannot be deleted via CromCull')">
					<LockIcon :size="18" />
					<span>{{ member.path }}</span>
				</span>
				<a :href="fileUrl(member.fileid)"
					target="_blank"
					rel="noopener"
					class="group-card__open-link"
					:title="t('cromcull', 'Open file in new tab')">
					<OpenInNewIcon :size="16" />
				</a>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import { formatSize } from '../utils/formatSize.js'

export default {
	name: 'GroupCard',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		OpenInNewIcon,
		LockIcon,
	},
	props: {
		group: {
			type: Object,
			required: true,
		},
		selectedFileIds: {
			type: Set,
			default: () => new Set(),
		},
		rechecking: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['toggle', 'hide', 'unhide', 'recheck'],
	setup(props) {
		const formattedSize = formatSize(props.group.size)

		const uncheckedCount = computed(() => {
			let count = 0
			for (const m of props.group.members) {
				if (!m.protected && !props.selectedFileIds.has(m.fileid)) {
					count++
				}
			}
			return count
		})

		function isDisabled(fileId) {
			if (props.selectedFileIds.has(fileId)) {
				return false
			}
			return uncheckedCount.value <= 1
		}

		function fileUrl(fileid) {
			return generateUrl('/f/{fileid}', { fileid })
		}

		return { formattedSize, isDisabled, fileUrl, t }
	},
}
</script>

<style scoped>
.group-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	margin-bottom: 12px;
	background: var(--color-main-background);
}

.group-card--hidden {
	opacity: 0.6;
}

.group-card__hidden-badge {
	font-size: 0.75em;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
	padding: 1px 6px;
	border-radius: var(--border-radius);
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

.group-card__header {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
}

.group-card__meta {
	display: flex;
	align-items: baseline;
	gap: 8px;
}

.group-card__size {
	font-weight: bold;
	font-size: 1.05em;
}

.group-card__count {
	color: var(--color-text-maxcontrast);
	font-size: 0.95em;
}

.group-card__hash {
	flex: 1;
	font-family: monospace;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
	word-break: break-all;
	min-width: 0;
}

.group-card__members {
	padding: 8px 16px;
}

.group-card__member {
	display: flex;
	align-items: center;
	gap: 4px;
	min-height: 36px;
}

.group-card__member--protected {
	opacity: 0.6;
}

.group-card__member :deep(.checkbox-radio-switch) {
	flex: 1;
	min-width: 0;
}

.group-card__member :deep(.checkbox-radio-switch__label) {
	word-break: break-all;
}

.group-card__protected-label {
	flex: 1;
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
	padding-left: 4px;
	color: var(--color-text-maxcontrast);
}

.group-card__protected-label span {
	word-break: break-all;
}

.group-card__open-link {
	flex-shrink: 0;
	display: flex;
	align-items: center;
	padding: 4px;
	color: var(--color-text-maxcontrast);
	border-radius: var(--border-radius);
}

.group-card__open-link:hover {
	color: var(--color-main-text);
	background: var(--color-background-hover);
}
</style>
