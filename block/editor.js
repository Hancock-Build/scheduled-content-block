/* Scheduling utilities are included here so editor insertion needs only one plugin script. */
(function (wp) {
    var date = wp.date;
    var format = 'Y-m-d\\TH:i';

    function parts(iso) {
        if (!iso) return { date: '', time: '' };
        var value = date.getDate(iso);
        if (isNaN(value.getTime())) return { date: '', time: '' };
        var local = date.date(format, value);
        return { date: local.slice(0, 10), time: local.slice(11, 16) };
    }

    function combine(day, time) {
        if (!day) return { iso: '' };
        var local = day + 'T' + (time || '00:00');
        var value = date.getDate(local);
        // Reject normalized dates, including nonexistent wall times during a DST jump.
        if (isNaN(value.getTime()) || date.date(format, value) !== local) {
            return { error: true };
        }
        return { iso: value.toISOString().replace(/\.000Z$/, 'Z') };
    }

    function timestamp(iso) {
        return iso ? date.getDate(iso).getTime() : null;
    }

    function status(attributes, now) {
        var start = timestamp(attributes.start);
        var end = timestamp(attributes.end);
        if ((start !== null && isNaN(start)) || (end !== null && isNaN(end)) ||
            (start !== null && end !== null && end < start)) return 'invalid';
        if (start === null && end === null) return 'unscheduled';
        if (start !== null && now < start) return 'upcoming';
        if (end !== null && now > end) return 'expired';
        return 'active';
    }

    function timezone() {
        var settings = date.getSettings().timezone;
        if (settings.string) return settings.string;
        var minutes = Math.round(Number(settings.offset || 0) * 60);
        var absolute = Math.abs(minutes);
        return 'UTC' + (minutes < 0 ? '-' : '+') +
            String(Math.floor(absolute / 60)).padStart(2, '0') + ':' + String(absolute % 60).padStart(2, '0');
    }

    window.scblkSchedule = { parts: parts, combine: combine, status: status, timezone: timezone };
})(window.wp);

(function (wp) {
    var el = wp.element.createElement;
    var __ = wp.i18n.__;
    var sprintf = wp.i18n.sprintf;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var be = wp.blockEditor;
    var c = wp.components;
    var schedule = window.scblkSchedule;

    var labels = {
        unscheduled: __('No schedule', 'scheduled-content-block'),
        upcoming: __('Upcoming', 'scheduled-content-block'),
        active: __('Active', 'scheduled-content-block'),
        expired: __('Expired', 'scheduled-content-block'),
        invalid: __('Invalid schedule', 'scheduled-content-block')
    };

    function readable(iso) {
        if (!iso) return '—';
        var value = wp.date.getDate(iso);
        return isNaN(value.getTime()) ? __('Invalid date', 'scheduled-content-block') : wp.date.dateI18n(wp.date.getSettings().formats.datetime, value);
    }

    wp.blocks.registerBlockType('h-b/scheduled-container', {
        edit: function (props) {
            var a = props.attributes;
            var setAttributes = props.setAttributes;
            var editorSettings = window.scblkEditorSettings;
            var previewAvailable = !!editorSettings && typeof editorSettings.visitorBypass === 'boolean';
            var previewState = useState(false);
            var preview = previewState[0] && previewAvailable;
            var previewTimeState = useState('');
            var previewTime = previewTimeState[0];
            var nowState = useState(Date.now());
            var errorsState = useState({});
            var errors = errorsState[0];
            var setErrors = errorsState[1];
            var blockProps = be.useBlockProps({ className: 'scblk-editor-frame' });
            var innerProps = be.useInnerBlocksProps({ className: 'scblk-editor-inner' }, { templateLock: false });
            var prefix = 'scblk-' + props.clientId;

            useEffect(function () {
                var timer = setInterval(function () { nowState[1](Date.now()); }, 1000);
                return function () { clearInterval(timer); };
            }, []);

            var currentStatus = schedule.status(a, nowState[0]);
            var previewResult = schedule.combine(previewTime.slice(0, 10), previewTime.slice(11));
            var previewStatus = schedule.status(a, previewTime && !previewResult.error ? new Date(previewResult.iso).getTime() : nowState[0]);
            var visitorBypass = previewAvailable && editorSettings.visitorBypass;
            var visible = visitorBypass || previewStatus === 'active' || previewStatus === 'unscheduled';

            function fieldError(key, value) {
                setErrors(function (previous) {
                    var next = Object.assign({}, previous);
                    next[key] = value;
                    return next;
                });
            }

            function dateControls(key, title) {
                var parts = schedule.parts(a[key]);
                function update(day, time) {
                    var result = schedule.combine(day, time);
                    if (result.error) {
                        fieldError(key, __('That local time does not exist in the site timezone. Choose another time.', 'scheduled-content-block'));
                        return;
                    }
                    fieldError(key, '');
                    var update = {};
                    update[key] = result.iso;
                    setAttributes(update);
                }
                return el('fieldset', { className: 'scblk-datetime-group' },
                    el('legend', null, title),
                    el('div', { className: 'scblk-datetime-row' },
                        el('div', null,
                            el('label', { htmlFor: prefix + '-' + key + '-date' }, __('Date', 'scheduled-content-block')),
                            el('input', { id: prefix + '-' + key + '-date', type: 'date', value: parts.date,
                                className: 'components-text-control__input',
                                onChange: function (e) { update(e.target.value, parts.time); } })),
                        el('div', null,
                            el('label', { htmlFor: prefix + '-' + key + '-time' }, __('Time', 'scheduled-content-block')),
                            el('input', { id: prefix + '-' + key + '-time', type: 'time', value: parts.time,
                                className: 'components-text-control__input', disabled: !parts.date,
                                onChange: function (e) { update(parts.date, e.target.value); } }))),
                    el(c.Button, { variant: 'tertiary', disabled: !a[key], onClick: function () { update('', ''); } },
                        key === 'start' ? __('Clear start', 'scheduled-content-block') : __('Clear end', 'scheduled-content-block')),
                    errors[key] ? el(c.Notice, { status: 'error', isDismissible: false }, errors[key]) : null);
            }

            return el(wp.element.Fragment, null,
                el(be.InspectorControls, null,
                    el(c.PanelBody, { title: __('Display Schedule', 'scheduled-content-block'), initialOpen: true },
                        el('p', null, sprintf(__('Site timezone: %s', 'scheduled-content-block'), schedule.timezone())),
                        el('p', null, __('Leave a boundary empty for no start or no end. During the repeated hour when daylight saving ends, the earlier occurrence is used.', 'scheduled-content-block')),
                        dateControls('start', __('Start', 'scheduled-content-block')),
                        dateControls('end', __('End', 'scheduled-content-block')),
                        currentStatus === 'invalid' ? el(c.Notice, { status: 'error', isDismissible: false },
                            __('The end must be at or after the start, and both dates must be valid. Visitors will see the hidden state until you correct the schedule.', 'scheduled-content-block')) : null),
                    el(c.PanelBody, { title: __('Visibility Options', 'scheduled-content-block'), initialOpen: false },
                        el(c.ToggleControl, {
                            label: __('Automatically delete block once the end date has passed.', 'scheduled-content-block'),
                            checked: !!a.deleteAfterEnd, onChange: function (v) { setAttributes({ deleteAfterEnd: v }); },
                            help: __('Deletes this container and everything inside it from the saved post. Revision recovery depends on WordPress revisions being enabled. Copy recovered content and turn deletion off before saving it again.', 'scheduled-content-block')
                        }),
                        a.deleteAfterEnd && !a.end ? el(c.Notice, { status: 'warning', isDismissible: false },
                            __('Set an end date to enable automatic deletion.', 'scheduled-content-block')) : null,
                        el(c.ToggleControl, {
                            label: __('Show a placeholder message when hidden', 'scheduled-content-block'),
                            checked: !!a.showPlaceholder, onChange: function (v) { setAttributes({ showPlaceholder: v }); }
                        }),
                        a.showPlaceholder ? el(c.TextareaControl, {
                            label: __('Placeholder text', 'scheduled-content-block'), value: a.placeholderText,
                            onChange: function (v) { setAttributes({ placeholderText: v }); }
                        }) : null),
                    el(c.PanelBody, { title: __('Visitor Preview (editor only)', 'scheduled-content-block'), initialOpen: false },
                        el(c.ToggleControl, {
                            label: __('Preview visitor visibility', 'scheduled-content-block'), checked: preview,
                            onChange: previewState[1], disabled: !previewAvailable,
                            help: !previewAvailable ? __('Visitor preview settings could not load. Reload the editor or check your site’s script policy.', 'scheduled-content-block') : __('Simulates this container for logged-out visitors inside the editor. Preview controls and status labels never appear on your website.', 'scheduled-content-block')
                        }),
                        preview ? el('div', null,
                            el('label', { htmlFor: prefix + '-preview-time' }, __('Preview at site date and time', 'scheduled-content-block')),
                            el('input', { id: prefix + '-preview-time', type: 'datetime-local', value: previewTime,
                                onChange: function (e) { previewTimeState[1](e.target.value); } }),
                            el(c.Button, { variant: 'tertiary', onClick: function () { previewTimeState[1](''); } }, __('Use current time', 'scheduled-content-block')),
                            previewResult.error ? el(c.Notice, { status: 'error', isDismissible: false }, __('Choose a valid time in the site timezone.', 'scheduled-content-block')) : null,
                            visitorBypass ? el('p', null, __('Your site settings allow visitors to bypass schedules, so this container remains visible.', 'scheduled-content-block')) : null) : null)),
                el('div', blockProps,
                    el(c.Notice, { status: currentStatus === 'invalid' ? 'warning' : 'info', isDismissible: false },
                        el('strong', null, __('Scheduled Container', 'scheduled-content-block') + ': ' + labels[currentStatus]),
                        el('div', null, sprintf(__('Start: %1$s | End: %2$s', 'scheduled-content-block'), readable(a.start), readable(a.end)))),
                    preview ? el('div', { className: 'scblk-visitor-preview', role: 'status' },
                        el('strong', null, __('Visitor preview', 'scheduled-content-block') + ': ' + labels[previewStatus]),
                        el('p', null, __('This preview applies to this container’s schedule. Nested containers follow their own schedules.', 'scheduled-content-block')),
                        !visible ? el('p', null, a.showPlaceholder ? a.placeholderText : __('Hidden — no content is shown to visitors.', 'scheduled-content-block')) : null) : null,
                    el('div', { hidden: preview && !visible }, el('div', innerProps))));
        },
        save: function () { return el(be.InnerBlocks.Content, null); }
    });
})(window.wp);
