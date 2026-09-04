<template>
	<div class="scan-progress">
		<div class="scan-progress__bar-wrap">
			<NcProgressBar :value="percent" size="medium" />
		</div>
		<div class="scan-progress__info">
			<span class="scan-progress__text">{{ text }}</span>
			<NcButton type="tertiary-no-background" @click="$emit('cancel')">
				{{ t('cromcull', 'Cancel') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcProgressBar, NcButton } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'

export default {
	name: 'ScanProgress',
	components: { NcProgressBar, NcButton },
	props: {
		percent: {
			type: Number,
			default: 0,
		},
		text: {
			type: String,
			default: '',
		},
	},
	emits: ['cancel'],
	setup() {
		return { t }
	},
}
</script>

<style scoped>
.scan-progress {
	padding: 12px 0;
}

.scan-progress__bar-wrap {
	margin-bottom: 8px;
}

.scan-progress__info {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.scan-progress__text {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
