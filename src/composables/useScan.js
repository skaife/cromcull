import { ref, computed } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { formatSize } from '../utils/formatSize.js'

export function useScan() {
	const groups = ref([])
	const scanning = ref(false)
	const scanned = ref(false)
	const cancelled = ref(false)

	const stats = ref(null)
	const scanProgress = ref(null)

	const progressText = computed(() => {
		if (!scanProgress.value) return ''
		const { filesProcessed, totalFiles, currentSize } = scanProgress.value
		const sizeLabel = currentSize !== null ? formatSize(currentSize) : ''
		if (sizeLabel) {
			return `${filesProcessed.toLocaleString()} of ${totalFiles.toLocaleString()} candidates (checking ${sizeLabel} files)`
		}
		return `${filesProcessed.toLocaleString()} of ${totalFiles.toLocaleString()} candidates`
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

	async function startScan(resume = false) {
		cancelled.value = false
		scanning.value = true

		try {
			const statsData = await fetchStats()
			const { candidate_sizes, candidate_files, chunk_budget, incomplete_scan } = statsData

			if (candidate_sizes.length === 0) {
				scanned.value = true
				scanning.value = false
				return
			}

			const startResponse = await axios.post(generateUrl('/apps/cromcull/scan/start'), {
				resume,
			})
			const { scan_id, processed_sizes } = startResponse.data

			const processedSet = new Set(processed_sizes.map(Number))
			const remainingSizes = candidate_sizes.filter(s => !processedSet.has(s.size))

			let alreadyProcessedFiles = 0
			for (const s of candidate_sizes) {
				if (processedSet.has(s.size)) {
					alreadyProcessedFiles += s.count
				}
			}

			const totalFiles = candidate_files
			const chunks = buildChunks(remainingSizes, chunk_budget)

			scanProgress.value = {
				filesProcessed: alreadyProcessedFiles,
				totalFiles,
				currentSize: remainingSizes.length > 0 ? remainingSizes[0].size : null,
			}

			if (startResponse.data.resumed) {
				await loadGroups()
			}

			for (const chunk of chunks) {
				if (cancelled.value) break

				const sizes = chunk.map(e => e.size)
				const chunkFileCount = chunk.reduce((sum, e) => sum + e.count, 0)

				scanProgress.value = {
					...scanProgress.value,
					currentSize: chunk[0].size,
				}

				await axios.post(generateUrl('/apps/cromcull/scan/chunk'), {
					scan_id,
					sizes,
				})

				scanProgress.value = {
					...scanProgress.value,
					filesProcessed: scanProgress.value.filesProcessed + chunkFileCount,
				}

				await loadGroups()
			}

			if (!cancelled.value) {
				await axios.post(generateUrl('/apps/cromcull/scan/finish'))
			}

			scanned.value = true
		} finally {
			scanning.value = false
			scanProgress.value = null
		}
	}

	function cancelScan() {
		cancelled.value = true
	}

	async function loadGroups() {
		const response = await axios.get(generateUrl('/apps/cromcull/groups'))
		groups.value = response.data
		if (groups.value.length > 0) {
			scanned.value = true
		}
	}

	async function dismissGroup(id) {
		await axios.post(generateUrl('/apps/cromcull/groups/{id}/dismiss', { id }))
		groups.value = groups.value.filter(g => g.id !== id)
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
						if (e.response && e.response.status === 409) {
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
		groups, scanning, scanned, cancelled,
		stats, scanProgress, progressText, progressPercent,
		fetchStats, startScan, cancelScan,
		loadGroups, dismissGroup, deleteFiles,
	}
}
