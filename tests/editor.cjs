// Run with SCBLK_BROWSER_CONFIG pointing to a private JSON file containing local
// baseURL, username, password and postId. Requires Playwright and Chromium.
const { chromium } = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const config = JSON.parse(fs.readFileSync(process.env.SCBLK_BROWSER_CONFIG, 'utf8'));
const base = config.baseURL || 'http://127.0.0.1:8081';
let checks = 0;
function check(condition, label) { assert.ok(condition, label); console.log('PASS: ' + label); checks++; }
(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.SCBLK_CHROMIUM_PATH || '/usr/bin/chromium', args: ['--no-sandbox'] });
    try {
        // Deliberately use a timezone different from the WordPress site.
        const context = await browser.newContext({ timezoneId: 'Asia/Tokyo' });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', e => errors.push(e.message));
        await page.goto(base + '/wp-login.php');
        await page.locator('#user_login').fill(config.username);
        await page.locator('#user_pass').fill(config.password);
        await Promise.all([page.waitForURL('**/wp-admin/**'), page.locator('#wp-submit').click()]);
        await page.goto(base + '/wp-admin/post.php?post=' + config.postId + '&action=edit');
        await page.waitForFunction(() => window.scblkSchedule && wp.data.select('core/block-editor'));
        const dismiss = page.getByRole('button', { name: /Close|Get started/i });
        if (await dismiss.count()) await dismiss.first().click();
        const results = await page.evaluate(() => {
            const s = window.scblkSchedule;
            const d = wp.date;
            const original = d.getSettings();
            const results = {};
            results.timezone = s.timezone();
            results.summer = s.combine('2026-07-01', '12:00').iso;
            results.winter = s.combine('2026-01-01', '12:00').iso;
            results.roundtrip = s.parts('2026-07-01T17:00:00Z');
            results.gap = s.combine('2026-03-08', '02:30').error;
            results.repeat = s.combine('2026-11-01', '01:30').iso;
            results.cleared = s.combine('', '12:00').iso;
            results.midnight = s.combine('2026-07-01', '').iso;
            const time = Date.parse('2026-07-01T17:00:00Z');
            results.statuses = [s.status({}, time), s.status({start:'2026-07-02T00:00:00Z'},time),
                s.status({end:'2026-07-01T00:00:00Z'},time), s.status({start:'2026-07-01T00:00:00Z'},time),
                s.status({start:'2026-07-02T00:00:00Z',end:'2026-07-01T00:00:00Z'},time), s.status({start:'bad-date'},time)];
            d.setSettings(Object.assign({}, original, { timezone: { string: '', offset: 5.5, abbr: '' } }));
            results.fixed = s.combine('2026-07-01','12:00').iso;
            results.fixedLabel = s.timezone();
            d.setSettings(original);
            return results;
        });
        check(results.timezone === 'America/Chicago', 'site timezone displayed despite Tokyo browser');
        check(results.summer === '2026-07-01T17:00:00Z' && results.winter === '2026-01-01T18:00:00Z', 'summer and winter site conversions');
        check(results.roundtrip.date === '2026-07-01' && results.roundtrip.time === '12:00', 'stored UTC round-trips to site time');
        check(results.gap === true, 'nonexistent daylight saving time rejected');
        check(results.repeat === '2026-11-01T06:30:00Z', 'repeated daylight saving hour uses earlier occurrence');
        check(results.cleared === '' && results.midnight === '2026-07-01T05:00:00Z', 'clear boundary and midnight default');
        check(results.fixed === '2026-07-01T06:30:00Z' && results.fixedLabel === 'UTC+05:30', 'fixed offset timezone conversion and label');
        check(JSON.stringify(results.statuses) === JSON.stringify(['unscheduled','upcoming','expired','active','invalid','invalid']), 'all schedule statuses');

        await page.getByRole('button', {name:'Settings',exact:true}).waitFor();
        const id = await page.evaluate(() => {
            const block = wp.blocks.createBlock('h-b/scheduled-container', { start: '2099-07-01T17:00:00Z', showPlaceholder: true, placeholderText: '<Coming soon>' }, [wp.blocks.createBlock('core/paragraph', { content: 'SCBLK_PRIVATE_MARKER' })]);
            wp.data.dispatch('core/block-editor').resetBlocks([block]);
            wp.data.dispatch('core/block-editor').selectBlock(block.clientId);
            wp.data.dispatch('core/edit-post').openGeneralSidebar('edit-post/block');
            return block.clientId;
        });
        await page.getByRole('button', { name: 'Display Schedule', exact: true }).waitFor();
        await page.getByText('Site timezone: America/Chicago', {exact:true}).waitFor();
        check(await page.getByText('Site timezone: America/Chicago', { exact: true }).count() === 1, 'timezone visible beside date controls');
        await page.getByRole('button', { name: 'Clear start', exact: true }).click();
        check(await page.evaluate(id => wp.data.select('core/block-editor').getBlock(id).attributes.start === '', id), 'clear start empties attribute');
        await page.evaluate(id => wp.data.dispatch('core/block-editor').updateBlockAttributes(id, {end:'2099-07-01T17:00:00Z'}), id);
        await page.getByRole('button', {name:'Clear end',exact:true}).click();
        check(await page.evaluate(id => wp.data.select('core/block-editor').getBlock(id).attributes.end === '', id), 'clear end empties attribute');
        await page.evaluate(async () => { await wp.data.dispatch('core/editor').savePost(); });
        await page.reload();
        await page.waitForFunction(() => window.scblkSchedule && wp.data.select('core/block-editor'));
        check(await page.evaluate(() => wp.data.select('core/block-editor').getBlocks()[0].attributes.start === '' && wp.data.select('core/block-editor').getBlocks()[0].attributes.end === ''), 'cleared boundaries remain empty after save and reopen');
        const id2 = await page.evaluate(() => {
            const b = wp.blocks.createBlock('h-b/scheduled-container', {}, [wp.blocks.createBlock('core/paragraph', {content: 'SCBLK_PRIVATE_MARKER'})]);
            wp.data.dispatch('core/block-editor').resetBlocks([b]);
            wp.data.dispatch('core/block-editor').selectBlock(b.clientId);
            return b.clientId;
        });
        await page.waitForTimeout(300);
        check(await page.evaluate(id => wp.data.select('core/block-editor').getBlock(id).attributes.start === '', id2), 'new unscheduled block does not acquire a start date');
        await page.evaluate(id => wp.data.dispatch('core/block-editor').updateBlockAttributes(id, {start:'2099-07-01T17:00:00Z',end:'2098-07-01T17:00:00Z'}), id2);
        await page.getByText(/The end must be at or after the start/).waitFor();
        check(await page.evaluate(id => wp.data.select('core/block-editor').getBlock(id).attributes.end === '2098-07-01T17:00:00Z', id2), 'invalid range reports error without changing dates');
        await page.evaluate(id => wp.data.dispatch('core/block-editor').updateBlockAttributes(id, {end:'', showPlaceholder:true, placeholderText:'<Coming soon>'}), id2);
        await page.getByRole('button', {name:'Visitor Preview (editor only)', exact:true}).click();
        const before = await page.evaluate(() => wp.data.select('core/editor').getEditedPostContent());
        await page.getByLabel('Preview visitor visibility', {exact:true}).check();
        // The editor canvas may live in an iframe depending on WordPress configuration.
        const canvas = await page.locator('iframe[name="editor-canvas"]').count() ? page.frameLocator('iframe[name="editor-canvas"]') : page;
        const preview = canvas.locator('.scblk-visitor-preview');
        await preview.first().waitFor();
        check((await preview.first().textContent()).includes('<Coming soon>'), 'hidden preview shows placeholder as plain text');
        check(!await canvas.getByText('SCBLK_PRIVATE_MARKER', {exact:true}).isVisible(), 'upcoming content hidden only in editor simulation');
        await page.evaluate(() => { window.scblkEditorSettings.visitorBypass = true; });
        await page.waitForTimeout(1100);
        check(await canvas.getByText('SCBLK_PRIVATE_MARKER', {exact:true}).isVisible(), 'preview honors configured visitor bypass');
        await page.evaluate(() => { window.scblkEditorSettings.visitorBypass = false; });
        await page.waitForTimeout(1100);
        const after = await page.evaluate(() => wp.data.select('core/editor').getEditedPostContent());
        check(before === after && !after.includes('scblk-visitor-preview'), 'preview toggle never changes serialized content');
        await page.getByLabel('Preview at site date and time', {exact:true}).fill('2100-07-01T12:00');
        await page.waitForTimeout(100);
        check(await canvas.getByText('SCBLK_PRIVATE_MARKER', {exact:true}).isVisible(), 'active preview shows inner content');
        check((await preview.first().textContent()).includes('Active'), 'chosen preview time updates simulated status');
        await page.getByRole('button', {name:'Use current time',exact:true}).click();
        check((await preview.first().textContent()).includes('Upcoming'), 'current time restores upcoming status');
        await page.getByLabel('Preview visitor visibility', {exact:true}).uncheck();
        await page.evaluate(async () => { wp.data.dispatch('core/editor').editPost({status:'publish'}); await wp.data.dispatch('core/editor').savePost(); });
        const stored = await page.evaluate(() => wp.data.select('core/editor').getEditedPostContent());
        check(stored.includes('SCBLK_PRIVATE_MARKER') && !stored.includes('previewTime'), 'preview leaves inner blocks intact and is not saved');

        // Verify frontend response without editor-only markup or scripts.
        const guest = await browser.newContext();
        const response = await guest.request.get(base + '/?p=' + config.postId);
        const html = await response.text();
        check(response.ok() && html.includes('&lt;Coming soon&gt;') && !html.includes('SCBLK_PRIVATE_MARKER'), 'logged-out frontend obeys schedule and escapes placeholder');
        await guest.close();
        check(!html.includes('scblk-visitor-preview') && !html.includes('/block/editor.js') && !html.includes('/block/schedule.js'), 'frontend excludes preview controls and editor scripts');
        await page.goto(base + '/wp-admin/options-general.php?page=scblk-settings');
        await page.locator('input[value="editor"]').check();
        await page.getByRole('button', {name:'Save Changes',exact:true}).click();
        check(await page.locator('input[value="editor"]').isChecked(), 'Editor role survives settings form save');
        for (const checkbox of await page.locator('input[type="checkbox"][name="scblk_visibility_roles[]"]').all()) await checkbox.uncheck();
        await page.getByRole('button', {name:'Save Changes',exact:true}).click();
        check(await page.locator('input[type="checkbox"][name="scblk_visibility_roles[]"]:checked').count() === 0, 'all bypass roles can be cleared through settings');
        await page.locator('input[value="administrator"]').check();
        await page.getByRole('button', {name:'Save Changes',exact:true}).click();
        check(errors.length === 0, 'no browser JavaScript exceptions: ' + errors.join('; '));
        console.log(checks + ' editor/browser checks passed');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
