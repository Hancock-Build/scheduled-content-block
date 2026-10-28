/* Scheduling utilities shared by the editor controls and visitor preview. Editor only. */
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
