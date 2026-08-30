import { ref, computed } from 'vue'

export function useSelection() {
	const selections = ref(new Map())

	function isSelected(groupId, fileId) {
		return selections.value.get(groupId)?.has(fileId) || false
	}

	function getGroupSelectedIds(groupId) {
		return selections.value.get(groupId) || new Set()
	}

	function toggleFile(groupId, fileId) {
		const next = new Map(selections.value)
		const groupSet = new Set(next.get(groupId) || [])

		if (groupSet.has(fileId)) {
			groupSet.delete(fileId)
		} else {
			groupSet.add(fileId)
		}

		if (groupSet.size === 0) {
			next.delete(groupId)
		} else {
			next.set(groupId, groupSet)
		}

		selections.value = next
	}

	const selectedCount = computed(() => {
		let count = 0
		for (const set of selections.value.values()) {
			count += set.size
		}
		return count
	})

	const selectedGroupCount = computed(() => selections.value.size)

	function clearSelection() {
		selections.value = new Map()
	}

	function clearGroupSelection(groupId) {
		const next = new Map(selections.value)
		next.delete(groupId)
		selections.value = next
	}

	return {
		selections,
		isSelected,
		getGroupSelectedIds,
		toggleFile,
		selectedCount,
		selectedGroupCount,
		clearSelection,
		clearGroupSelection,
	}
}
