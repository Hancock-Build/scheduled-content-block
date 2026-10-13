(function (wp) {
    'use strict';

    var el = wp.element.createElement;
    var __ = wp.i18n.__;
    var settings = window.scblkAISettings || {};

    // Generated content is plain text. RichText attributes need escaped HTML.
    function escapeText(text) {
        return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;').replace(/\r?\n/g, '<br>');
    }

    function draftBlocks(draft) {
        var blocks = [];
        if (draft.heading) {
            blocks.push(wp.blocks.createBlock('core/heading', { level: 2, content: escapeText(draft.heading) }));
        }
        return blocks.concat(draft.paragraphs.map(function (text) {
            return wp.blocks.createBlock('core/paragraph', { content: escapeText(text) });
        }));
    }

    wp.scblkAIPanel = function (props) {
        var promptState = wp.element.useState('');
        var prompt = promptState[0], setPrompt = promptState[1];
        var draftState = wp.element.useState(null);
        var draft = draftState[0], setDraft = draftState[1];
        var busyState = wp.element.useState(false);
        var busy = busyState[0], setBusy = busyState[1];
        var errorState = wp.element.useState('');
        var error = errorState[0], setError = errorState[1];
        var mounted = wp.element.useRef(true);
        wp.element.useEffect(function () {
            mounted.current = true;
            return function () { mounted.current = false; };
        }, []);
        var postId = wp.data.useSelect(function (select) {
            var editor = select('core/editor');
            return editor ? editor.getCurrentPostId() : 0;
        }, []);
        var hasContent = wp.data.useSelect(function (select) {
            return select('core/block-editor').getBlockCount(props.clientId) > 0;
        }, [props.clientId]);

        function generate() {
            if (busy || !prompt.trim() || !postId) { return; }
            setBusy(true);
            setError('');
            setDraft(null);
            wp.apiFetch({
                path: '/scheduled-content-block/v1/draft',
                method: 'POST',
                data: { post_id: postId, prompt: prompt }
            }).then(function (result) {
                if (mounted.current) { setDraft(result); }
            }).catch(function (failure) {
                if (mounted.current) {
                    setError(failure.message || __('Unable to generate a draft. Please try again.', 'scheduled-content-block'));
                }
            }).finally(function () {
                if (mounted.current) { setBusy(false); }
            });
        }

        function applyDraft() {
            var blocks = draftBlocks(draft);
            var store = wp.data.select('core/block-editor');
            if (!store.canInsertBlocks(blocks, props.clientId) || store.getTemplateLock(props.clientId)) {
                setError(__('Unlock this container before applying AI content.', 'scheduled-content-block'));
                return;
            }
            wp.data.dispatch('core/block-editor').replaceInnerBlocks(props.clientId, blocks, false);
            props.setAttributes({ start: draft.start, end: draft.end });
            setDraft(null);
        }

        var help = '';
        if (!settings.enabled) {
            help = __('Enable AI block creation in Settings → Scheduled Content to use prompts.', 'scheduled-content-block');
        } else if (!settings.available) {
            help = __('Requires the WordPress AI Client and a configured text-generation provider.', 'scheduled-content-block');
        } else if (!settings.canPrompt) {
            help = __('You need the prompt_ai permission to generate a draft.', 'scheduled-content-block');
        } else if (!postId) {
            help = __('Save the post as a draft before generating a block.', 'scheduled-content-block');
        }

        return el(wp.components.PanelBody, {
            title: __('Create with AI', 'scheduled-content-block'), initialOpen: false
        }, help ? el('p', null, help) : el(wp.element.Fragment, null,
            el('p', null, __('Describe the content and when it should appear. Dates use the site timezone. Only your prompt and the site time are sent to the configured AI provider.', 'scheduled-content-block')),
            el(wp.components.TextareaControl, {
                label: __('Content and schedule prompt', 'scheduled-content-block'),
                help: __('Example: Write a weekend sale announcement, visible from Friday at 9am until Sunday at 6pm.', 'scheduled-content-block'),
                value: prompt, maxLength: 2000, disabled: busy,
                onChange: function (value) { setPrompt(value); setDraft(null); setError(''); }
            }),
            el(wp.components.Button, {
                variant: 'secondary', onClick: generate, isBusy: busy, disabled: busy || !prompt.trim()
            }, busy ? __('Generating…', 'scheduled-content-block') : __('Generate draft', 'scheduled-content-block')),
            error ? el(wp.components.Notice, { status: 'error', isDismissible: true, onRemove: function () { setError(''); } }, error) : null,
            draft ? el('div', { className: 'scblk-ai-draft', 'aria-live': 'polite' },
                el('h3', null, __('Review draft', 'scheduled-content-block')),
                el('p', null, __('Start: ', 'scheduled-content-block') + props.formatReadable(draft.start)),
                el('p', null, __('End: ', 'scheduled-content-block') + (draft.end ? props.formatReadable(draft.end) : __('No end date', 'scheduled-content-block'))),
                draft.heading ? el('h4', null, draft.heading) : null,
                draft.paragraphs.map(function (text, index) { return el('p', { key: index }, text); }),
                el('p', null, hasContent
                    ? __('Applying this draft replaces the content inside this container and updates its schedule. Save or update the post to publish your changes.', 'scheduled-content-block')
                    : __('Applying this draft adds content and updates this container’s schedule. Save or update the post to publish your changes.', 'scheduled-content-block')),
                el(wp.components.Button, { variant: 'primary', onClick: applyDraft }, hasContent
                    ? __('Replace content and apply schedule', 'scheduled-content-block')
                    : __('Apply content and schedule', 'scheduled-content-block')),
                el(wp.components.Button, { variant: 'tertiary', onClick: function () { setDraft(null); } }, __('Discard draft', 'scheduled-content-block'))
            ) : null
        ));
    };
})(window.wp);
