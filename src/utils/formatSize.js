const UNITS = ['B', 'KB', 'MB', 'GB', 'TB']

export function formatSize(bytes) {
	if (bytes === 0) return '0 B'
	const i = Math.floor(Math.log(bytes) / Math.log(1024))
	const size = bytes / Math.pow(1024, i)
	return `${size.toFixed(i === 0 ? 0 : 1)} ${UNITS[i]}`
}
