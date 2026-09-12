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

					<ScanProgress v-if="scanning"
						:percent="progressPercent"
						:text="progressText"
						@cancel="cancelScan" />

					<UserSettings @saved="refreshStats" />

					<div v-if="groups.length > 0" class="cromcull__results">
						<div class="cromcull__results-toolbar">
							<p class="cromcull__results-count">
								{{ t('cromcull', '{count} duplicate groups found', { count: totalGroupCount }) }}
							</p>
							<NcCheckboxRadioSwitch :model-value="showHidden"
								@update:model-value="handleToggleHidden">
								{{ t('cromcull', 'Show hidden') }}
							</NcCheckboxRadioSwitch>
						</div>
						<GroupCard v-for="group in groups"
							:key="group.id"
							:group="group"
							:selected-file-ids="getGroupSelectedIds(group.id)"
							:rechecking="recheckingGroupId === group.id"
							@toggle="(fid) => toggleFile(group.id, fid)"
							@recheck="handleRecheck(group.id)"
							@hide="handleHide(group.id)"
							@unhide="handleUnhide(group.id)" />
						<NcButton v-if="hasMore"
							type="tertiary"
							class="cromcull__load-more"
							@click="showMore">
							{{ t('cromcull', 'Show more ({remaining} remaining)', { remaining: totalGroupCount - groups.length }) }}
						</NcButton>
					</div>

					<div v-else-if="scanned && !scanning" class="cromcull__empty-with-toggle">
						<NcCheckboxRadioSwitch :model-value="showHidden"
							@update:model-value="handleToggleHidden">
							{{ t('cromcull', 'Show hidden') }}
						</NcCheckboxRadioSwitch>
						<NcEmptyContent :name="t('cromcull', 'No duplicates found')">
							<template #icon>
								<CheckCircleIcon :size="64" />
							</template>
						</NcEmptyContent>
					</div>

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
import { NcContent, NcAppContent, NcEmptyContent, NcButton, NcLoadingIcon, NcCheckboxRadioSwitch } from '@nextcloud/vue'
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
		NcCheckboxRadioSwitch,
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
			groups, totalGroupCount, scanning, scanned, cancelled,
			stats, scanProgress, progressText, progressPercent,
			fetchStats, startScan, cancelScan,
			showHidden,
			loadGroups, hideGroup, unhideGroup, recheckGroup, deleteFiles,
		} = useScan()
		const {
			selections, getGroupSelectedIds, toggleFile, selectedCount,
			selectedGroupCount, clearSelection, clearGroupSelection,
		} = useSelection()

		const statsLoading = ref(false)
		const showStats = ref(false)

		const groupIndex = computed(() => {
			const idx = new Map()
			for (const g of groups.value) {
				idx.set(g.id, g)
			}
			return idx
		})

		const hasMore = computed(() => {
			return groups.value.length < totalGroupCount.value
		})

		async function showMore() {
			await loadGroups({ append: true })
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
		const recheckingGroupId = ref(null)

		onMounted(async () => {
			await loadGroups()
			await refreshStats()
		})

		async function refreshStats() {
			statsLoading.value = true
			try {
				await fetchStats()
				showStats.value = true
			} catch (e) {
				// silent
			} finally {
				statsLoading.value = false
			}
		}

		async function handleScanClick() {
			clearSelection()
			try {
				const result = await startScan()
				if (result?.chunkErrors > 0) {
					showError(t('cromcull', 'Some file groups could not be checked — results may be incomplete'))
				}
			} catch (e) {
				showError(t('cromcull', 'Scan failed — please try again'))
				await loadGroups()
			}
			await refreshStats()
		}

		async function handleHide(groupId) {
			clearGroupSelection(groupId)
			try {
				await hideGroup(groupId)
			} catch (e) {
				showError(t('cromcull', 'Failed to hide group'))
			}
		}

		async function handleUnhide(groupId) {
			try {
				await unhideGroup(groupId)
			} catch (e) {
				showError(t('cromcull', 'Failed to un-hide group'))
			}
		}

		async function handleToggleHidden(val) {
			showHidden.value = val
			await loadGroups()
		}

		async function handleRecheck(groupId) {
			recheckingGroupId.value = groupId
			try {
				const result = await recheckGroup(groupId)
				if (result.status === 'removed') {
					showSuccess(t('cromcull', 'Group is no longer a duplicate'))
				} else {
					showSuccess(t('cromcull', 'Group verified — {count} copies confirmed', { count: result.members }))
				}
			} catch (e) {
				showError(t('cromcull', 'Failed to recheck group'))
			} finally {
				recheckingGroupId.value = null
			}
		}

		async function handleDelete() {
			showConfirmModal.value = false
			deleting.value = true
			try {
				const results = await deleteFiles(selections.value)
				clearSelection()
				const failed = results.filter(r => r.status !== 'resolved')
				if (failed.length > 0) {
					const msg = failed[0].message || t('cromcull', 'Some groups changed since scanning — please rescan')
					showError(msg)
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
			groups, totalGroupCount, scanning, scanned,
			stats, scanProgress, progressText, progressPercent,
			statsLoading, showStats,
			formattedTotal, formattedCandidates,
			hasMore, showMore,
			getGroupSelectedIds, toggleFile,
			selectedCount, selectedGroupCount, selectedTotalSize,
			showConfirmModal, deleting,
			showHidden, recheckingGroupId,
			handleScanClick,
			cancelScan,
			handleHide, handleUnhide, handleToggleHidden,
			handleRecheck, handleDelete,
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

.cromcull__results-toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 12px;
}

.cromcull__results-count {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}

.cromcull__empty-with-toggle {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
}

.cromcull__load-more {
	display: block;
	margin: 12px auto;
}
</style>
