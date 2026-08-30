<template>
	<NcDialog :name="t('cromcull', 'Confirm Deletion')"
		:buttons="dialogButtons"
		@update:open="onOpenChange">
		<p>{{ message }}</p>
	</NcDialog>
</template>

<script>
import { computed } from 'vue'
import { NcDialog } from '@nextcloud/vue'
import { t, n } from '@nextcloud/l10n'
import { formatSize } from '../utils/formatSize.js'

export default {
	name: 'ConfirmDeleteModal',
	components: { NcDialog },
	props: {
		fileCount: {
			type: Number,
			required: true,
		},
		groupCount: {
			type: Number,
			required: true,
		},
		totalSize: {
			type: Number,
			required: true,
		},
	},
	emits: ['confirm', 'cancel'],
	setup(props, { emit }) {
		let confirmed = false

		const message = computed(() => {
			const files = n('cromcull', '{count} file', '{count} files', props.fileCount, { count: props.fileCount })
			const groups = n('cromcull', '{count} group', '{count} groups', props.groupCount, { count: props.groupCount })
			const size = formatSize(props.totalSize)
			return t('cromcull', '{files} across {groups} ({size}) will be moved to trash.', { files, groups, size })
		})

		const dialogButtons = [
			{
				label: t('cromcull', 'Cancel'),
				callback: () => { emit('cancel') },
			},
			{
				label: t('cromcull', 'Delete'),
				variant: 'error',
				callback: () => {
					confirmed = true
					emit('confirm')
				},
			},
		]

		function onOpenChange(open) {
			if (!open && !confirmed) {
				emit('cancel')
			}
		}

		return { message, dialogButtons, onOpenChange, t }
	},
}
</script>
