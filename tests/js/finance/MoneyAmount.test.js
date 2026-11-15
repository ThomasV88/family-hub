import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { createAppI18n } from '../../../resources/js/i18n.js';
import MoneyAmount from '../../../resources/js/Finance/MoneyAmount.vue';
import { formatMinor } from '../../../resources/js/Finance/money.js';

describe('MoneyAmount', () => {
    it.each(['en', 'sk', 'pl'])('renders formatMinor output for %s', (l) => {
        const w = mount(MoneyAmount, { props: { minor: '123450', currency: 'EUR' }, global: { plugins: [createAppI18n(l, {})] } });
        const span = w.get('span');
        expect(span.classes()).toContain('money');
        expect(span.attributes('style')).toContain('tabular-nums');
        expect(span.text()).toBe(formatMinor('123450', 'EUR', l).trim());
    });
    it('shows a plus when signed and U+2212 for negatives', () => {
        const plugins = [createAppI18n('en', {})];
        expect(mount(MoneyAmount, { props: { minor: '500', currency: 'EUR', signed: true }, global: { plugins } }).text().startsWith('+')).toBe(true);
        expect(mount(MoneyAmount, { props: { minor: '-500', currency: 'EUR' }, global: { plugins } }).text().startsWith('−')).toBe(true);
    });
});
