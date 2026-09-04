<template>
	<div class="delete-bar">
		<span class="delete-bar__summary">
			{{ deleting ? t('cromcull', 'Deleting...') : summary }}
		</span>
		<NcButton type="error" :disabled="deleting" @click="$emit('delete')">
			<template v-if="deleting" #icon>
				<NcLoadingIcon :size="20" />
			</template>
			{{ deleting ? t('cromcull', 'Deleting...') : t('cromcull', 'Delete Selected') }}
		</NcButton>
	</div>
</template>

<script>
import { computed } from 'vue'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { t, n } from '@nextcloud/l10n'
import { formatSize } from '../utils/formatSize.js'

export default {
	name: 'DeleteBar',
	components: { NcButton, NcLoadingIcon },
	props: {
		selectedCount: {
			type: Number,
			required: true,
		},
		selectedGroupCount: {
			type: Number,
			required: true,
		},
		totalSize: {
			type: Number,
			required: true,
		},
		deleting: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['delete'],
	setup(props) {
		const summary = computed(() => {
			const files = n('cromcull', '{count} file', '{count} files', props.selectedCount, { count: props.selectedCount })
			const groups = n('cromcull', '{count} group', '{count} groups', props.selectedGroupCount, { count: props.selectedGroupCount })
			const size = formatSize(props.totalSize)
			return t('cromcull', '{files} selected across {groups} ({size})', { files, groups, size })
		})

		return { summary, t }
	},
}
</script>

<style scoped>
.delete-bar {
	position: sticky;
	bottom: 0;
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 20px;
	background: var(--color-main-background);
	border-top: 2px solid var(--color-error);
	z-index: 100;
}

.delete-bar__summary {
	font-weight: bold;
}
</style>
