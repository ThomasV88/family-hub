import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { filterTopics, fold, parseInline, parseTarget } from '../../../resources/js/Manual/inline.js';

describe('inline manual markup', () => {
    it('parses bold text, anchor links and app links', () => {
        expect(parseInline('Press **Save** or [see this](tasks#reminders) or [open](/settings/security).')).toEqual([
            { type: 'text', text: 'Press ' },
            { type: 'bold', text: 'Save' },
            { type: 'text', text: ' or ' },
            { type: 'link', text: 'see this', target: { kind: 'anchor', topic: 'tasks', section: 'reminders' } },
            { type: 'text', text: ' or ' },
            { type: 'link', text: 'open', target: { kind: 'app', path: '/settings/security' } },
            { type: 'text', text: '.' },
        ]);
    });

    it.each(['https://evil.example/x', '//evil.example', 'javascript:alert(1)', 'data:text/html,x', '/a b', '/x?y=1', 'Tasks#a'])(
        'never turns %s into a link',
        (target) => {
            expect(parseTarget(target)).toBeNull();
            expect(parseInline(`[x](${target})`).every((s) => s.type === 'text')).toBe(true);
        },
    );

    it('keeps angle brackets as plain text (no HTML is ever parsed)', () => {
        expect(parseInline('<img src=x onerror=alert(1)>')).toEqual([{ type: 'text', text: '<img src=x onerror=alert(1)>' }]);
    });

    it('folds accents so a search finds words with or without them', () => {
        expect(fold('Začíname Zdrowie Łódź')).toBe('zaciname zdrowie lodz');
    });

    it('filters topics by every word, across title, summary and section text', () => {
        const topics = [
            { id: 'a', title: 'Tasks', summary: 'Lists', sections: [{ id: 's1', title: 'Reminders', blocks: [{ type: 'p', text: 'We **email** you.' }] }, { id: 's2', title: 'Other', blocks: [] }] },
        ];
        expect(filterTopics(topics, '').length).toBe(1);
        expect(filterTopics(topics, 'REMINDERS email')[0].sections.map((s) => s.id)).toEqual(['s1']);
        expect(filterTopics(topics, 'tasks')[0].sections.length).toBe(2);
        expect(filterTopics(topics, 'nothing here')).toEqual([]);
    });
});

describe('manual components', () => {
    it('never use v-html', () => {
        const dirs = ['resources/js/Manual', 'resources/js/Pages/Manual'];
        for (const dir of dirs)
            for (const f of readdirSync(`${process.cwd()}/${dir}`)) {
                expect(readFileSync(`${process.cwd()}/${dir}/${f}`, 'utf8'), `${dir}/${f}`).not.toContain('v-html');
            }
    });
});
