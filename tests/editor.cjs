// Uses WordPress's real date API, with a browser timezone different from the site.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({
        executablePath: process.env.CHROMIUM_PATH || undefined,
        headless: true,
        args: ['--no-sandbox']
    });
    try {
        const page = await browser.newPage({ timezoneId: 'Pacific/Honolulu' });
        const base = (process.env.WP_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
        await page.goto(base);
        for (const asset of ['vendor/moment.min.js', 'hooks.min.js', 'i18n.min.js', 'deprecated.min.js', 'date.min.js']) {
            await page.addScriptTag({ url: `${base}/wp-includes/js/dist/${asset}` });
        }
        const editor = fs.readFileSync(path.join(__dirname, '../block/editor.js'), 'utf8');
        await page.addScriptTag({ content: editor.slice(0, editor.indexOf('})(window.wp);') + '})(window.wp);'.length) });
        const cases = JSON.parse(fs.readFileSync(path.join(__dirname, 'schedule-cases.json'), 'utf8'));
        for (const test of cases) {
            const actual = await page.evaluate(test => {
                const fixed = /^[+-]/.test(test.timezone);
                const sign = test.timezone.startsWith('-') ? -1 : 1;
                const offset = fixed ? sign * (Number(test.timezone.slice(1, 3)) + Number(test.timezone.slice(4, 6)) / 60) : 0;
                wp.date.setSettings(Object.assign({}, wp.date.getSettings(), { timezone: { string: fixed ? '' : test.timezone, offset, offsetFormatted: test.timezone, abbr: '' } }));
                return scblkSchedule.status(test.attributes, Date.parse(test.at));
            }, test);
            assert.equal(actual, test.expected, test.name);
        }
        console.log(`${cases.length} editor recurrence fixtures passed with WordPress date API`);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
