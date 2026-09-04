<template>
	<NcContent app-name="cromcull">
		<NcAppContent>
			<div class="cromcull">
				<div class="cromcull__header">
					<h2>{{ t('cromcull', 'Duplicate Files') }}</h2>
					<div class="cromcull__header-actions">
						<NcButton v-if="!scanning" @click="handleScanClick">
							<template #icon>
								<MagnifyIcon :size="20" />
							</template>
							{{ t('cromcull', 'Scan for Duplicates') }}
						</NcButton>
					</div>
				</div>

				<div class="cromcull__content">
					<div v-if="statsLoading" class="cromcull__stats-loading">
						<NcLoadingIcon :size="20" />
						<span>{{ t('cromcull', 'Loading file statistics...') }}</span>
					</div>

					<div v-if="showStats && !scanning" class="cromcull__stats">
						<div class="cromcull__stats-numbers">
							<span class="cromcull__stats-total">
								{{ t('cromcull', '{total} files', { total: formattedTotal }) }}
							</span>
							<span class="cromcull__stats-separator">&mdash;</span>
							<span class="cromcull__stats-candidates">
								{{ t('cromcull', '{count} match candidates', { count: formattedCandidates }) }}
							</span>
						</div>
					</div>

					<div v-if="showResumePrompt && !scanning" class="cromcull__resume">
						<p>
							{{ t('cromcull', 'The previous scan was incomplete. Would you like to continue or restart?') }}
						</p>
						<div class="cromcull__resume-actions">
							<NcButton type="primary" @click="handleResume">
								{{ t('cromcull', 'Continue') }}
							</NcButton>
							<NcButton @click="handleRestart">
								{{ t('cromcull', 'Restart') }}
							</NcButton>
						</div>
					</div>

					<ScanProgress v-if="scanning"
						:percent="progressPercent"
						:text="progressText"
						@cancel="cancelScan" />

					<UserSettings @saved="refreshStats" />

					<div v-if="groups.length > 0" class="cromcull__results">
						<p class="cromcull__results-count">
							{{ t('cromcull', '{count} duplicate groups found', { count: groups.length }) }}
						</p>
						<GroupCard v-for="group in visibleGroups"
							:key="group.id"
							:group="group"
							:selected-file-ids="getGroupSelectedIds(group.id)"
							@toggle="(fid) => toggleFile(group.id, fid)"
							@dismiss="handleDismiss(group.id)" />
						<NcButton v-if="hasMore"
							type="tertiary"
							class="cromcull__load-more"
							@click="showMore">
							{{ t('cromcull', 'Show more ({remaining} remaining)', { remaining: groups.length - displayCount }) }}
						</NcButton>
					</div>

					<NcEmptyContent v-else-if="scanned && !scanning && !showResumePrompt"
						:name="t('cromcull', 'No duplicates found')">
						<template #icon>
							<CheckCircleIcon :size="64" />
						</template>
					</NcEmptyContent>

					<NcEmptyContent v-else-if="!scanning && !showStats && !statsLoading"
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
					:deleting="deleting"
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
import ScanProgress from './components/ScanProgress.vue'
import UserSettings from './components/UserSettings.vue'
import { useScan } from './composables/useScan.js'
import { useSelection } from './composables/useSelection.js'

const PAGE_SIZE = 25

function formatCount(n) {
	if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M'
	if (n >= 1000) return (n / 1000).toFixed(1) + 'K'
	return String(n)
}

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
		ScanProgress,
		UserSettings,
	},
	setup() {
		const {
			groups, scanning, scanned, cancelled,
			stats, scanProgress, progressText, progressPercent,
			fetchStats, startScan, cancelScan,
			loadGroups, dismissGroup, deleteFiles,
		} = useScan()
		const {
			selections, getGroupSelectedIds, toggleFile, selectedCount,
			selectedGroupCount, clearSelection, clearGroupSelection,
		} = useSelection()

		const statsLoading = ref(false)
		const showStats = ref(false)
		const showResumePrompt = ref(false)
		const displayCount = ref(PAGE_SIZE)

		const groupIndex = computed(() => {
			const idx = new Map()
			for (const g of groups.value) {
				idx.set(g.id, g)
			}
			return idx
		})

		const visibleGroups = computed(() => {
			return groups.value.slice(0, displayCount.value)
		})

		const hasMore = computed(() => {
			return displayCount.value < groups.value.length
		})

		function showMore() {
			displayCount.value += PAGE_SIZE
		}

		const formattedTotal = computed(() => {
			return stats.value ? formatCount(stats.value.total_files) : '0'
		})

		const formattedCandidates = computed(() => {
			return stats.value ? formatCount(stats.value.candidate_files) : '0'
		})

		const selectedTotalSize = computed(() => {
			let total = 0
			for (const [groupId, fileIds] of selections.value.entries()) {
				const group = groupIndex.value.get(groupId)
				if (group) {
					total += group.size * fileIds.size
				}
			}
			return total
		})

		const showConfirmModal = ref(false)
		const deleting = ref(false)

		onMounted(async () => {
			await loadGroups()
			await refreshStats()
		})

		async function refreshStats() {
			statsLoading.value = true
			try {
				await fetchStats()
				showStats.value = true
				showResumePrompt.value = !!(stats.value?.incomplete_scan?.status === 'incomplete')
			} catch (e) {
				// silent
			} finally {
				statsLoading.value = false
			}
		}

		async function handleScanClick() {
			if (stats.value?.incomplete_scan?.status === 'incomplete') {
				showResumePrompt.value = true
				return
			}
			clearSelection()
			displayCount.value = PAGE_SIZE
			await startScan(false)
			await refreshStats()
		}

		async function handleResume() {
			showResumePrompt.value = false
			clearSelection()
			await startScan(true)
			await refreshStats()
		}

		async function handleRestart() {
			showResumePrompt.value = false
			clearSelection()
			displayCount.value = PAGE_SIZE
			await startScan(false)
			await refreshStats()
		}

		async function handleDismiss(groupId) {
			clearGroupSelection(groupId)
			await dismissGroup(groupId)
		}

		async function handleDelete() {
			showConfirmModal.value = false
			deleting.value = true
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
			} finally {
				deleting.value = false
			}
		}

		return {
			groups, scanning, scanned,
			stats, scanProgress, progressText, progressPercent,
			statsLoading, showStats, showResumePrompt,
			formattedTotal, formattedCandidates,
			visibleGroups, hasMore, displayCount, showMore,
			getGroupSelectedIds, toggleFile,
			selectedCount, selectedGroupCount, selectedTotalSize,
			showConfirmModal, deleting,
			handleScanClick, handleResume, handleRestart,
			cancelScan,
			handleDismiss, handleDelete,
			refreshStats,
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

.cromcull__stats-loading {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.cromcull__stats {
	padding: 8px 0 12px;
}

.cromcull__stats-numbers {
	display: flex;
	align-items: baseline;
	gap: 8px;
	font-size: 1em;
}

.cromcull__stats-total {
	font-weight: 600;
}

.cromcull__stats-separator {
	color: var(--color-text-maxcontrast);
}

.cromcull__stats-candidates {
	color: var(--color-text-maxcontrast);
}

.cromcull__resume {
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius-large);
	padding: 16px;
	margin-bottom: 16px;
	background: var(--color-warning-hover, var(--color-background-hover));
}

.cromcull__resume p {
	margin: 0 0 12px 0;
}

.cromcull__resume-actions {
	display: flex;
	gap: 8px;
}

.cromcull__results-count {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0 0 12px 0;
}

.cromcull__load-more {
	display: block;
	margin: 12px auto;
}
</style>
