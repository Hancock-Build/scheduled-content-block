// Run with node --test tests/ai-editor.test.js. No external packages required.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const draft = {
    start: '2026-10-16T09:00:00-05:00', end: '2026-10-18T18:00:00-05:00',
    heading: 'Weekend sale', paragraphs: ['Save this weekend!']
};

function editor(options = {}) {
    let cursor = 0;
    const hooks = [];
    const requests = [], replacements = [], attributes = [];
    let cleanup;
    const settings = { enabled: true, available: true, canPrompt: true, ...options.settings };
    const wp = {
        element: {
            Fragment: 'Fragment',
            createElement: (type, props, ...children) => ({ type, props: props || {}, children: children.flat().filter(x => x !== null) }),
            useState: initial => {
                const index = cursor++;
                if (!(index in hooks)) hooks[index] = initial;
                return [hooks[index], value => { hooks[index] = value; }];
            },
            useRef: initial => {
                const index = cursor++;
                if (!(index in hooks)) hooks[index] = { current: initial };
                return hooks[index];
            },
            useEffect: callback => { if (!cleanup) cleanup = callback(); }
        },
        i18n: { __: value => value },
        components: { PanelBody: 'PanelBody', TextareaControl: 'TextareaControl', Button: 'Button', Notice: 'Notice' },
        blocks: { createBlock: (name, attrs) => ({ name, attributes: attrs }) },
        data: {
            useSelect: callback => callback(wp.data.select),
            select: store => store === 'core/editor' ? { getCurrentPostId: () => options.postId ?? 42 } : {
                getBlockCount: () => options.hasContent ? 1 : 0,
                canInsertBlocks: () => options.canInsert !== false,
                getTemplateLock: () => options.lock || false
            },
            dispatch: () => ({ replaceInnerBlocks: (...args) => replacements.push(args) })
        },
        apiFetch: args => {
            requests.push(args);
            return options.reject ? Promise.reject(options.reject) : Promise.resolve(options.draft || draft);
        }
    };
    vm.runInNewContext(fs.readFileSync(require.resolve('../block/ai.js'), 'utf8'), { window: { wp, scblkAISettings: settings } });
    function render() {
        cursor = 0;
        return wp.scblkAIPanel({ clientId: 'container-1', setAttributes: value => attributes.push(value), formatReadable: value => value });
    }
    function all(node) {
        return typeof node === 'object' && node ? [node, ...node.children.flatMap(all)] : [];
    }
    function find(type, label) {
        return all(render()).find(node => node.type === type && (!label || node.children.includes(label)));
    }
    async function generate() {
        find('TextareaControl').props.onChange('Write a sale announcement for this weekend.');
        find('Button', 'Generate draft').props.onClick();
        await new Promise(resolve => setImmediate(resolve));
    }
    return { render, all, find, generate, requests, replacements, attributes, unmount: () => cleanup() };
}

test('generating a draft uses the per-post route and leaves content and dates unchanged', async () => {
    const ui = editor();
    await ui.generate();
    assert.equal(ui.requests.length, 1);
    assert.equal(ui.requests[0].path, '/scheduled-content-block/v1/draft');
    assert.equal(ui.requests[0].method, 'POST');
    assert.equal(ui.requests[0].data.post_id, 42);
    assert.equal(ui.requests[0].data.prompt, 'Write a sale announcement for this weekend.');
    assert.equal(ui.replacements.length, 0);
    assert.equal(ui.attributes.length, 0);
    assert.ok(ui.find('Button', 'Apply content and schedule'));
});

test('applying a reviewed draft creates content blocks and sets the schedule', async () => {
    const ui = editor();
    await ui.generate();
    ui.find('Button', 'Apply content and schedule').props.onClick();
    const [clientId, blocks] = ui.replacements[0];
    assert.equal(clientId, 'container-1');
    assert.equal(blocks[0].name, 'core/heading');
    assert.equal(blocks[0].attributes.content, draft.heading);
    assert.equal(blocks[1].name, 'core/paragraph');
    assert.equal(blocks[1].attributes.content, draft.paragraphs[0]);
    assert.equal(ui.attributes[0].start, draft.start);
    assert.equal(ui.attributes[0].end, draft.end);
    assert.equal(Object.keys(ui.attributes[0]).length, 2);
    assert.equal(ui.find('Button', 'Apply content and schedule'), undefined);
});

test('existing content has an explicit replacement action', async () => {
    const ui = editor({ hasContent: true });
    await ui.generate();
    assert.ok(ui.find('Button', 'Replace content and apply schedule'));
    assert.equal(ui.replacements.length, 0);
});

test('AI text is escaped before becoming RichText block content', async () => {
    const ui = editor({ draft: { ...draft, heading: '', paragraphs: ['<img src=x onerror=alert(1)> & "sale"\nToday'] } });
    await ui.generate();
    ui.find('Button', 'Apply content and schedule').props.onClick();
    assert.equal(ui.replacements[0][1].length, 1);
    assert.equal(ui.replacements[0][1][0].attributes.content, '&lt;img src=x onerror=alert(1)&gt; &amp; &quot;sale&quot;<br>Today');
});

test('template locks and disallowed insertions prevent applying changes', async () => {
    for (const option of [{ lock: 'all' }, { canInsert: false }]) {
        const ui = editor(option);
        await ui.generate();
        ui.find('Button', 'Apply content and schedule').props.onClick();
        assert.equal(ui.replacements.length, 0);
        assert.equal(ui.attributes.length, 0);
        assert.ok(ui.find('Notice'));
    }
});

test('discarding and editing the prompt invalidate the previous draft', async () => {
    const ui = editor();
    await ui.generate();
    ui.find('Button', 'Discard draft').props.onClick();
    assert.equal(ui.find('Button', 'Apply content and schedule'), undefined);
    await ui.generate();
    ui.find('TextareaControl').props.onChange('A different request');
    assert.equal(ui.find('Button', 'Apply content and schedule'), undefined);
    assert.equal(ui.replacements.length, 0);
});

test('failed requests display an error and leave the post untouched', async () => {
    const ui = editor({ reject: { message: 'Configure a text-generation provider.' } });
    await ui.generate();
    assert.ok(ui.find('Notice').children.includes('Configure a text-generation provider.'));
    assert.equal(ui.find('Button', 'Apply content and schedule'), undefined);
    assert.equal(ui.replacements.length, 0);
    assert.equal(ui.find('Button', 'Generate draft').props.disabled, false);
});

test('missing setup or permission displays help instead of generation controls', () => {
    for (const options of [{ settings: { enabled: false } }, { settings: { available: false } }, { settings: { canPrompt: false } }, { postId: 0 }]) {
        const ui = editor(options);
        assert.equal(ui.find('Button', 'Generate draft'), undefined);
        assert.ok(ui.all(ui.render()).some(node => node.type === 'p'));
    }
});

test('empty prompts are disabled and unmounted panels ignore outstanding responses', async () => {
    const ui = editor();
    assert.equal(ui.find('Button', 'Generate draft').props.disabled, true);
    ui.find('TextareaControl').props.onChange('Create a block');
    ui.find('Button', 'Generate draft').props.onClick();
    assert.equal(ui.find('TextareaControl').props.disabled, true);
    ui.unmount();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(ui.find('Button', 'Apply content and schedule'), undefined);
    assert.equal(ui.replacements.length, 0);
});
