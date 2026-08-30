import { ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export function useScan() {
	const groups = ref([])
	const scanning = ref(false)
	const scanned = ref(false)

	async function triggerScan() {
		scanning.value = true
		try {
			await axios.post(generateUrl('/apps/cromcull/scan'))
			await loadGroups()
			scanned.value = true
		} finally {
			scanning.value = false
		}
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
		const results = []
		for (const [groupId, fileIds] of selections.entries()) {
			const group = groups.value.find(g => g.id === groupId)
			if (!group) continue

			const displayedPaths = {}
			for (const member of group.members) {
				displayedPaths[member.fileid] = member.path
			}

			try {
				const result = await axios.post(
					generateUrl('/apps/cromcull/groups/{id}/delete', { id: groupId }),
					{
						selectedFileIds: Array.from(fileIds),
						displayedPaths,
					}
				)
				results.push(result.data)
			} catch (e) {
				if (e.response && e.response.status === 409) {
					results.push(e.response.data)
				} else {
					throw e
				}
			}
		}
		await loadGroups()
		return results
	}

	return { groups, scanning, scanned, triggerScan, loadGroups, dismissGroup, deleteFiles }
}
