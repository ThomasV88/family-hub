import { formatWhen } from '../Ai/aiWhen.js';

// Pure mapping from a server item to what its tag shows. No I/O, nothing from the model is read.
// -> { labelKey, params, plural|null, variant, icon } ; `icon` is a name the list maps to a component.
export function itemView(item, appLocale = 'en', now = new Date()) {
    switch (item?.state) {
        case 'processing':
            return { labelKey: 'health.batch.state.processing', params: {}, plural: null, variant: 'neutral', icon: 'loader' };
        case 'paused':
            return { labelKey: 'health.batch.state.paused', params: { time: item.availableAt ? formatWhen(item.availableAt, appLocale, undefined, now) : '' }, plural: null, variant: 'neutral', icon: 'hourglass' };
        case 'review': {
            const count = Number(item.pending ?? 0);
            return { labelKey: 'health.batch.state.review', params: { count }, plural: count, variant: 'success', icon: 'check' };
        }
        case 'failed':
            return { labelKey: 'health.batch.state.failed', params: {}, plural: null, variant: 'danger', icon: 'x' };
        case 'stopped':
            return { labelKey: 'health.batch.state.stopped', params: {}, plural: null, variant: 'neutral', icon: 'ban' };
        case 'consent_off':
            return { labelKey: 'health.batch.state.consent_off', params: {}, plural: null, variant: 'neutral', icon: 'ban' };
        case 'done':
            return { labelKey: 'health.batch.state.done', params: {}, plural: null, variant: 'neutral', icon: 'check' };
        default:
            return { labelKey: 'health.batch.state.waiting', params: {}, plural: null, variant: 'neutral', icon: 'clock' };
    }
}

export const isBusy = (item) => item?.state === 'waiting' || item?.state === 'processing';
export const isPaused = (item) => item?.state === 'paused';
