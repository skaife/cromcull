<template>
	<NcContent app-name="cromcull">
		<NcAppContent>
			<div class="cromcull">
				<div class="cromcull__header">
					<h2>{{ t('cromcull', 'Duplicate Files') }}</h2>
					<NcButton :disabled="scanning" @click="handleScan">
						<template #icon>
							<NcLoadingIcon v-if="scanning" :size="20" />
							<MagnifyIcon v-else :size="20" />
						</template>
						{{ scanning ? t('cromcull', 'Scanning...') : t('cromcull', 'Scan for Duplicates') }}
					</NcButton>
				</div>

				<div class="cromcull__content">
					<div v-if="groups.length > 0" class="cromcull__results">
						<GroupCard v-for="group in groups"
							:key="group.id"
							:group="group"
							:selected-file-ids="getGroupSelectedIds(group.id)"
							@toggle="(fid) => toggleFile(group.id, fid)"
							@dismiss="handleDismiss(group.id)" />
					</div>

					<NcEmptyContent v-else-if="scanned && !scanning"
						:name="t('cromcull', 'No duplicates found')">
						<template #icon>
							<CheckCircleIcon :size="64" />
						</template>
					</NcEmptyContent>

					<NcEmptyContent v-else-if="!scanning"
						:name="t('cromcull', 'Duplicate Files')"
						:description="t('cromcull', 'Run a scan to find duplicate files')">
						<template #icon>
							<ContentCopyIcon :size="64" />
						</template>
					</NcEmptyContent>

					<ExcludedFolders />
				</div>

				<DeleteBar v-if="selectedCount > 0"
					:selected-count="selectedCount"
					:selected-group-count="selectedGroupCount"
					:total-size="selectedTotalSize"
					@delete="showConfirmModal = true" />
			</div>
		</NcAppContent>
	</NcContent>

	<ConfirmDeleteModal v-if="showConfirmModal"
		:file-count="selectedCount"
		:group-count="selectedGroupCount"
		:total-size="selectedTotalSize"
		@confirm="handleDelete"
		@cancel="showConfirmModal = false" />
</template>

<script>
import { NcContent, NcAppContent, NcEmptyContent, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { ref, computed, onMounted } from 'vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import GroupCard from './components/GroupCard.vue'
import DeleteBar from './components/DeleteBar.vue'
import ConfirmDeleteModal from './components/ConfirmDeleteModal.vue'
import ExcludedFolders from './components/ExcludedFolders.vue'
import { useScan } from './composables/useScan.js'
import { useSelection } from './composables/useSelection.js'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppContent,
		NcEmptyContent,
		NcButton,
		NcLoadingIcon,
		MagnifyIcon,
		ContentCopyIcon,
		CheckCircleIcon,
		GroupCard,
		DeleteBar,
		ConfirmDeleteModal,
		ExcludedFolders,
	},
	setup() {
		const { groups, scanning, scanned, triggerScan, loadGroups, dismissGroup, deleteFiles } = useScan()
		const {
			selections, getGroupSelectedIds, toggleFile, selectedCount,
			selectedGroupCount, clearSelection, clearGroupSelection,
		} = useSelection()

		const selectedTotalSize = computed(() => {
			let total = 0
			for (const [groupId, fileIds] of selections.value.entries()) {
				const group = groups.value.find(g => g.id === groupId)
				if (group) {
					total += group.size * fileIds.size
				}
			}
			return total
		})

		const showConfirmModal = ref(false)

		onMounted(() => loadGroups())

		async function handleScan() {
			clearSelection()
			await triggerScan()
		}

		async function handleDismiss(groupId) {
			clearGroupSelection(groupId)
			await dismissGroup(groupId)
		}

		async function handleDelete() {
			showConfirmModal.value = false
			try {
				const results = await deleteFiles(selections.value)
				clearSelection()
				const changed = results.filter(r => r.status === 'changed')
				if (changed.length > 0) {
					showError(t('cromcull', 'Some groups changed since scanning — please rescan'))
				} else {
					showSuccess(t('cromcull', 'Files moved to trash'))
				}
			} catch (e) {
				showError(t('cromcull', 'Failed to delete some files'))
			}
		}

		return {
			groups, scanning, scanned,
			getGroupSelectedIds, toggleFile,
			selectedCount, selectedGroupCount, selectedTotalSize,
			showConfirmModal,
			handleScan, handleDismiss, handleDelete,
			t,
		}
	},
}
</script>

<style scoped>
.cromcull {
	display: flex;
	flex-direction: column;
	height: 100%;
}

.cromcull__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 16px 20px;
	border-bottom: 1px solid var(--color-border);
	flex-shrink: 0;
}

.cromcull__header h2 {
	margin: 0;
	font-size: 1.3em;
}

.cromcull__content {
	flex: 1;
	overflow-y: auto;
	padding: 16px 20px;
	padding-bottom: 80px;
}
</style>
