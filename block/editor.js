(function (wp) {
    var registerBlockType = wp.blocks.registerBlockType;
    var __ = wp.i18n.__;
    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var useEffect = wp.element.useEffect;

    var be = wp.blockEditor || wp.editor;
    var InspectorControls = be.InspectorControls;
    var InnerBlocks = be.InnerBlocks;
    var BlockControls = be.BlockControls;
    var useBlockProps = be.useBlockProps;
    var useInnerBlocksProps = be.useInnerBlocksProps;

    var components = wp.components;
    var PanelBody = components.PanelBody;
    var ToggleControl = components.ToggleControl;
    var TextareaControl = components.TextareaControl;
    var ToolbarGroup = components.ToolbarGroup;
    var ToolbarButton = components.ToolbarButton;
    var Notice = components.Notice;

    var ALLOWED_BLOCKS = undefined; // allow any
    var TEMPLATE = []; // none

    function toISOZ(val){
        if (!val) return '';
        var d = new Date(val);
        if (isNaN(d.getTime())) return '';
        var iso = d.toISOString();
        return iso.replace(/\.\d{3}Z$/, 'Z');
    }

    function parseOffsetMinutes(tz) {
        var match = tz && tz.match(/^UTC([+-]\d{1,2})(?::(\d{2}))?$/);
        if (!match) return null;
        var sign = match[1][0] === '-' ? -1 : 1;
        var hours = parseInt(match[1].slice(1), 10);
        var mins = match[2] ? parseInt(match[2], 10) : 0;
        return sign * (hours * 60 + mins);
    }

    function isoToLocalParts(iso) {
        if (!iso) return { date: '', time: '' };
        var tz = getSiteTimeZone();
        if (wp.date && wp.date.moment) {
            var m = wp.date.moment(iso);
            var off = parseOffsetMinutes(tz);
            m = off === null ? m.tz(tz) : m.utcOffset(off);
            if (!m.isValid()) return { date: '', time: '' };
            return { date: m.format('YYYY-MM-DD'), time: m.format('HH:mm') };
        }
        var d = new Date(iso);
        if (isNaN(d.getTime())) return { date: '', time: '' };
        var pad = function (n) { return ('0' + n).slice(-2); };
        return {
            date: d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()),
            time: pad(d.getHours()) + ':' + pad(d.getMinutes())
        };
    }

    function combineLocal(dateStr, timeStr) {
        if (!dateStr && !timeStr) return '';
        var tz = getSiteTimeZone();
        if (wp.date && wp.date.moment) {
            var off = parseOffsetMinutes(tz);
            var moment = wp.date.moment;
            if (!dateStr) {
                var now = moment();
                now = off === null ? now.tz(tz) : now.utcOffset(off);
                dateStr = now.format('YYYY-MM-DD');
            }
            timeStr = timeStr || '00:00';
            var m = moment(dateStr + 'T' + timeStr);
            m = off === null ? m.tz(tz, true) : m.utcOffset(off, true);
            return m.utc().format('YYYY-MM-DDTHH:mm:ss[Z]');
        }
        if (!dateStr) {
            var nowDate = new Date();
            dateStr = nowDate.getFullYear() + '-' + ('0' + (nowDate.getMonth() + 1)).slice(-2) + '-' + ('0' + nowDate.getDate()).slice(-2);
        }
        timeStr = timeStr || '00:00';
        return toISOZ(dateStr + 'T' + timeStr);
    }

    function getSiteTimeZone() {
        try {
            if (wp.date && (wp.date.__experimentalGetSettings || wp.date.getSettings)) {
                var settings = wp.date.__experimentalGetSettings ? wp.date.__experimentalGetSettings() : wp.date.getSettings();
                if (settings && settings.timezone && settings.timezone.string) {
                    return settings.timezone.string;
                }
            }
        } catch (e) {}
        // Fallback: browser TZ or UTC
        try {
            return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
        } catch (e) {
            return 'UTC';
        }
    }

    function formatReadable(iso) {
        if (!iso) return '—';
        var tz = getSiteTimeZone();
        try {
            var d = new Date(iso);
            var datePart = new Intl.DateTimeFormat(undefined, { timeZone: tz, month: 'long', day: 'numeric', year: 'numeric' }).format(d);
            var timePart = new Intl.DateTimeFormat(undefined, { timeZone: tz, hour: 'numeric', minute: '2-digit', hour12: true }).format(d);
            timePart = timePart.toLowerCase().replace(' ', '');
            return datePart + ' at ' + timePart;
        } catch (e) {
            return iso;
        }
    }

    registerBlockType('h-b/scheduled-container', {
        edit: function (props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var start = attributes.start;
            var end = attributes.end;
            var showPlaceholder = attributes.showPlaceholder;
            var placeholderText = attributes.placeholderText;

            useEffect(function(){
                if (
                    !start &&
                    !end &&
                    showPlaceholder === false &&
                    !placeholderText
                ) {
                    setAttributes({ start: toISOZ(new Date()) });
                }
            }, []);

            function scheduleLabel() {
                return 'Start: ' + formatReadable(start) + ' | End: ' + formatReadable(end);
            }

            var blockProps = useBlockProps({ className: 'scb-editor-frame' });
            var innerBlocksProps = useInnerBlocksProps(
                { className: 'scb-editor-inner' },
                { allowedBlocks: ALLOWED_BLOCKS, template: TEMPLATE, templateLock: false }
            );

            return el(
                Fragment,
                null,
                el(
                    BlockControls,
                    null,
                    el(
                        ToolbarGroup,
                        null,
                        el(ToolbarButton, { icon: 'clock', label: __('Schedule Details', 'scheduled-content-block'), disabled: true })
                    )
                ),
                el(
                    InspectorControls,
                    null,
                    el(
                        PanelBody,
                        { title: __('Display Schedule', 'scheduled-content-block'), initialOpen: true },
                        el('p', { className: 'components-help' }, __('Choose your start and end date for content to be displayed. Dates and times use the site timezone.', 'scheduled-content-block')),
                        (function(){
                            var sp = isoToLocalParts(start);
                            return el('div', { className: 'scb-datetime-group' },
                                el('label', { className: 'components-base-control__label' }, __('Start', 'scheduled-content-block')),
                                el('div', { className: 'scb-datetime-row' },
                                    el('input', {
                                        type: 'date',
                                        className: 'components-text-control__input',
                                        value: sp.date,
                                        onChange: function(e){ setAttributes({ start: combineLocal(e.target.value, sp.time) }); }
                                    }),
                                    el('input', {
                                        type: 'time',
                                        className: 'components-text-control__input',
                                        value: sp.time,
                                        onChange: function(e){ setAttributes({ start: combineLocal(sp.date, e.target.value) }); }
                                    })
                                )
                            );
                        })(),
                        el('div', { style: { height: '10px' } }),
                        (function(){
                            var ep = isoToLocalParts(end);
                            return el('div', { className: 'scb-datetime-group' },
                                el('label', { className: 'components-base-control__label' }, __('End', 'scheduled-content-block')),
                                el('div', { className: 'scb-datetime-row' },
                                    el('input', {
                                        type: 'date',
                                        className: 'components-text-control__input',
                                        value: ep.date,
                                        onChange: function(e){ setAttributes({ end: combineLocal(e.target.value, ep.time) }); }
                                    }),
                                    el('input', {
                                        type: 'time',
                                        className: 'components-text-control__input',
                                        value: ep.time,
                                        onChange: function(e){ setAttributes({ end: combineLocal(ep.date, e.target.value) }); }
                                    })
                                )
                            );
                        })()
                    ),
                    el(
                        PanelBody,
                        { title: __('Visibility Options', 'scheduled-content-block'), initialOpen: false },
                        el(ToggleControl, {
                            label: __('Show a placeholder message when hidden', 'scheduled-content-block'),
                            checked: !!showPlaceholder,
                            onChange: function (v) { setAttributes({ showPlaceholder: !!v }); }
                        }),
                        showPlaceholder ? el(TextareaControl, {
                            label: __('Placeholder text', 'scheduled-content-block'),
                            value: placeholderText,
                            onChange: function (v) { setAttributes({ placeholderText: v }); }
                        }) : null
                    )
                ),
                el(
                    'div',
                    blockProps,
                    el(Notice, { status: 'info', isDismissible: false },
                        el('strong', null, __('Scheduled Container', 'scheduled-content-block')),
                        ': ' + scheduleLabel()
                    ),
                    el('div', innerBlocksProps)
                )
            );
        },
        save: function () {
            return el(InnerBlocks.Content, null);
        }
    });
})(window.wp);
