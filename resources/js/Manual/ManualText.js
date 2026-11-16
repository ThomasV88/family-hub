import { h, inject } from 'vue';
import { Link } from '@inertiajs/vue3';
import { parseInline, sectionAnchor, topicAnchor } from './inline.js';

// Renders one string of manual text as vnodes (text, <strong>, links). Built only from vnodes, never from raw HTML.
export default {
    name: 'ManualText',
    props: { text: { type: String, required: true } },
    setup(props) {
        const goto = inject('manual-goto', () => {});
        return () =>
            parseInline(props.text).map((seg) => {
                if (seg.type === 'bold') return h('strong', seg.text);
                if (seg.type !== 'link') return seg.text;
                const t = seg.target;
                if (t.kind === 'app') return h(Link, { href: t.path, class: 'manual-link' }, () => seg.text);
                const id = t.section ? sectionAnchor(t.topic, t.section) : topicAnchor(t.topic);
                return h(
                    'a',
                    {
                        href: `#${id}`,
                        class: 'manual-link',
                        onClick: (event) => {
                            event.preventDefault();
                            goto({ topic: t.topic, section: t.section });
                        },
                    },
                    seg.text,
                );
            });
    },
};
