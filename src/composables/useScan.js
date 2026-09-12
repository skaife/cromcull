import { ref, computed } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { formatSize } from '../utils/formatSize.js'

const PAGE_SIZE = 25

export function useScan() {
	const groups = ref([])
	const totalGroupCount = ref(0)
	const scanning = ref(false)
	const scanned = ref(false)
	const cancelled = ref(false)

	const stats = ref(null)
	const scanProgress = ref(null)

	const progressText = computed(() => {
		if (!scanProgress.value) return ''
		const { filesProcessed, totalFiles, currentSize, cacheHits, cacheMisses, groupsFound } = scanProgress.value
		const sizeLabel = currentSize !== null ? formatSize(currentSize) : ''
		let text = `${filesProcessed.toLocaleString()} of ${totalFiles.toLocaleString()} candidates`
		if (sizeLabel) {
			text += ` (checking ${sizeLabel} files)`
		}
		if (groupsFound > 0) {
			text += ` — ${groupsFound} duplicate groups found`
		}
		const totalLookups = cacheHits + cacheMisses
		if (totalLookups > 0) {
			const rate = Math.round((cacheHits / totalLookups) * 100)
			text += ` — cache: ${rate}% hit (${cacheHits.toLocaleString()} / ${totalLookups.toLocaleString()})`
		}
		return text
	})

	const progressPercent = computed(() => {
		if (!scanProgress.value || scanProgress.value.totalFiles === 0) return 0
		return Math.round((scanProgress.value.filesProcessed / scanProgress.value.totalFiles) * 100)
	})

	async function fetchStats() {
		const response = await axios.get(generateUrl('/apps/cromcull/scan/stats'))
		stats.value = response.data
		return response.data
	}

	function buildChunks(candidateSizes, chunkBudget) {
		const chunks = []
		let currentChunk = []
		let currentBytes = 0

		for (const entry of candidateSizes) {
			const entryBytes = entry.size * entry.count

			if (currentChunk.length > 0 && currentBytes + entryBytes > chunkBudget) {
				chunks.push(currentChunk)
				currentChunk = []
				currentBytes = 0
			}

			currentChunk.push(entry)
			currentBytes += entryBytes
		}

		if (currentChunk.length > 0) {
			chunks.push(currentChunk)
		}

		return chunks
	}

	async function startScan() {
		cancelled.value = false
		scanning.value = true

		try {
			const statsData = await fetchStats()
			const { candidate_sizes, candidate_files, chunk_budget } = statsData

			if (candidate_sizes.length === 0) {
				scanned.value = true
				scanning.value = false
				return
			}

			const startResponse = await axios.post(generateUrl('/apps/cromcull/scan/start'))
			const { scan_id } = startResponse.data

			const totalFiles = candidate_files
			const chunks = buildChunks(candidate_sizes, chunk_budget)

			scanProgress.value = {
				filesProcessed: 0,
				totalFiles,
				currentSize: candidate_sizes.length > 0 ? candidate_sizes[0].size : null,
				cacheHits: 0,
				cacheMisses: 0,
				groupsFound: 0,
			}

			let chunkErrors = 0
			for (const chunk of chunks) {
				if (cancelled.value) break

				const sizes = chunk.map(e => e.size)
				const chunkFileCount = chunk.reduce((sum, e) => sum + e.count, 0)

				scanProgress.value = {
					...scanProgress.value,
					currentSize: chunk[0].size,
				}

				try {
					const chunkResponse = await axios.post(generateUrl('/apps/cromcull/scan/chunk'), {
						scan_id,
						sizes,
					})

					scanProgress.value = {
						...scanProgress.value,
						filesProcessed: scanProgress.value.filesProcessed + chunkFileCount,
						cacheHits: scanProgress.value.cacheHits + (chunkResponse.data.cache_hits || 0),
						cacheMisses: scanProgress.value.cacheMisses + (chunkResponse.data.cache_misses || 0),
						groupsFound: scanProgress.value.groupsFound + (chunkResponse.data.groups_found || 0),
					}
				} catch (e) {
					chunkErrors++
					scanProgress.value = {
						...scanProgress.value,
						filesProcessed: scanProgress.value.filesProcessed + chunkFileCount,
					}
				}
			}

			if (!cancelled.value) {
				try {
					await axios.post(generateUrl('/apps/cromcull/scan/finish'))
				} catch (e) {
					// finish failed but results may still be in the DB
				}
			}

			await loadGroups()
			scanned.value = true
			return { chunkErrors }
		} finally {
			scanning.value = false
			scanProgress.value = null
		}
	}

	function cancelScan() {
		cancelled.value = true
	}

	const showHidden = ref(false)

	async function loadGroups({ append = false } = {}) {
		const params = {}
		if (showHidden.value) params.showHidden = '1'
		if (append) {
			params.limit = PAGE_SIZE
			params.offset = groups.value.length
		} else {
			params.limit = Math.max(groups.value.length, PAGE_SIZE)
			params.offset = 0
		}
		const response = await axios.get(generateUrl('/apps/cromcull/groups'), { params })
		const { groups: fetched, total } = response.data
		if (append) {
			groups.value = [...groups.value, ...fetched]
		} else {
			groups.value = fetched
		}
		totalGroupCount.value = total
		if (groups.value.length > 0) {
			scanned.value = true
		}
	}

	async function hideGroup(id) {
		await axios.post(generateUrl('/apps/cromcull/groups/{id}/hide', { id }))
		if (!showHidden.value) {
			groups.value = groups.value.filter(g => g.id !== id)
			totalGroupCount.value = Math.max(0, totalGroupCount.value - 1)
		} else {
			const group = groups.value.find(g => g.id === id)
			if (group) {
				group.hidden = true
			}
		}
	}

	async function unhideGroup(id) {
		await axios.post(generateUrl('/apps/cromcull/groups/{id}/unhide', { id }))
		const group = groups.value.find(g => g.id === id)
		if (group) {
			group.hidden = false
		}
	}

	async function recheckGroup(groupId) {
		const response = await axios.post(
			generateUrl('/apps/cromcull/groups/{id}/recheck', { id: groupId }),
		)
		await loadGroups()
		return response.data
	}

	async function deleteFiles(selections) {
		const promises = []
		for (const [groupId, fileIds] of selections.entries()) {
			const group = groups.value.find(g => g.id === groupId)
			if (!group) continue

			const displayedPaths = {}
			for (const member of group.members) {
				displayedPaths[member.fileid] = member.path
			}

			promises.push(
				axios.post(
					generateUrl('/apps/cromcull/groups/{id}/delete', { id: groupId }),
					{
						selectedFileIds: Array.from(fileIds),
						displayedPaths,
					},
				)
					.then(r => r.data)
					.catch(e => {
						if (e.response?.data?.message) {
							return e.response.data
						}
						return { status: 'error', message: 'Request failed' }
					}),
			)
		}
		const results = await Promise.all(promises)
		await loadGroups()
		return results
	}

	return {
		groups, totalGroupCount, scanning, scanned, cancelled,
		stats, scanProgress, progressText, progressPercent,
		fetchStats, startScan, cancelScan,
		showHidden,
		loadGroups, hideGroup, unhideGroup, recheckGroup, deleteFiles,
	}
}
