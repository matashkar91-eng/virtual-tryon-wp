#!/usr/bin/env node
/* Real browser checks on disposable local WP. All generation stays in demo. */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const playwright = require(process.env.VTO_PLAYWRIGHT || '/opt/codex/cua_node/lib/node_modules/playwright-core');
const php = process.env.VTO_PHP || 'php';
const root = process.env.VTO_WP_ROOT;
if (!root) throw new Error('Set VTO_WP_ROOT to disposable WordPress.');
const fixture = path.join(__dirname, 'design-gallery-fixture.php');
const artifacts = path.resolve(process.env.VTO_ARTIFACTS || path.join(__dirname, '../artifacts/design-gallery'));
fs.mkdirSync(artifacts, {recursive:true});
function wp(action, payload = {}) {
    return JSON.parse(execFileSync(php, [fixture, action], {input:JSON.stringify(payload), env:process.env, encoding:'utf8'}));
}
let checks = 0;
function check(value, message) {
    if (!value) throw new Error(message);
    checks++;
    process.stdout.write('PASS ' + message + '\n');
}
(async () => {
    let state;
    let browser;
    let createdTokens = [];
    try {
        state = wp('setup');
        let verification = wp('verify');
        check(!verification.woo_loaded, 'Gallery boots with WooCommerce deactivated');
        check(verification.catalog.length === 2 && verification.catalog.every(p => p.id.startsWith('custom:')), 'Only active standalone artworks enter catalog');
        browser = await playwright.chromium.launch({executablePath:process.env.VTO_CHROMIUM || '/usr/bin/chromium', headless:true, args:['--no-sandbox','--disable-dev-shm-usage']});
        const context = await browser.newContext({viewport:{width:1440,height:1100}, deviceScaleFactor:1, reducedMotion:'reduce'});
        let externalRequests = 0;
        await context.route('**/*', async route => {
            const url = new URL(route.request().url());
            if (!['127.0.0.1','localhost'].includes(url.hostname) && ['http:','https:'].includes(url.protocol)) { externalRequests++; return route.abort(); }
            return route.continue();
        });
        await context.addCookies(state.cookies);
        const admin = await context.newPage();
        await admin.goto(state.base + 'wp-admin/admin.php?page=vto-designs');
        await admin.locator('#vto-design-form').waitFor();
        check(await admin.locator('[data-vto-design-card]').count() === 3, 'Administrator sees active and hidden artwork cards');
        await admin.locator('.vto-add-designs').first().click();
        const libraryTab = admin.locator('.media-modal #menu-item-browse');
        if (await libraryTab.count()) await libraryTab.click();
        const targetMedia = admin.locator('.media-modal .attachment[data-id="' + state.attachment_ids[2] + '"]');
        await targetMedia.waitFor({timeout:15000});
        await targetMedia.click();
        await admin.locator('.media-modal .media-button-select').click();
        await admin.locator('[data-vto-design-card]').nth(3).waitFor();
        check(await admin.locator('[data-vto-design-card]').count() === 4, 'WordPress media picker adds a raw artwork without a WooCommerce product');
        const newCard = admin.locator('[data-vto-design-card]').nth(3);
        await newCard.locator('[data-vto-design-name]').fill('گل‌های اناری — طرح تازه');
        check(await newCard.locator('[data-vto-active]').isChecked(), 'New artwork is active by default');
        await Promise.all([admin.waitForURL(/settings-updated=true/), admin.locator('#vto-design-form #submit').click()]);
        verification = wp('verify');
        check(verification.source === 'designs' && verification.catalog.length === 3 && verification.design_count === 4, 'Saving independent gallery retains disabled design and publishes three active designs');
        check(verification.credential_preserved && verification.retention_hours === 18 && verification.mode === 'demo', 'Gallery-only form preserves saved credential, retention and execution mode');
        check(verification.quality === 'low', 'Gallery uses economical image quality');
        check(!verification.woo_loaded, 'Admin media/save flow works without loading WooCommerce');
        await admin.evaluate(() => document.fonts.ready);
        await admin.screenshot({path:path.join(artifacts,'admin-gallery.png'),fullPage:true});

        const customer = await context.newPage();
        const errors = [];
        customer.on('pageerror', error => errors.push(error.message));
        let generationPosts = 0;
        customer.on('request', request => {
            if (request.method() === 'POST' && /(?:vto\/v1\/jobs|rest_route=.*vto.*jobs)/.test(request.url())) generationPosts++;
        });
        await customer.goto(state.page_url);
        const app = customer.locator('[data-vto-app]');
        await app.waitFor();
        await customer.evaluate(() => document.fonts.ready);
        check(await app.locator('input[name="product_id"]').count() === 3, 'Customer sees only active raw designs');
        check(!(await app.textContent()).includes('طرح غیرفعال'), 'Disabled artwork is absent from customer markup');
        check(await app.locator('[data-vto-submit]').isDisabled(), 'Build button is disabled until design, photo and consent are ready');
        check(await app.locator('.vto-product-visual img').first().evaluate(el => getComputedStyle(el).objectFit) === 'contain', 'Full artwork print is shown without display cropping');
        check(await app.evaluate(el => getComputedStyle(el).fontFamily.includes('Vazirmatn')) && await customer.evaluate(() => document.fonts.check('16px Vazirmatn')), 'Bundled Persian Vazirmatn font loads locally');
        await app.locator('.vto-product').nth(2).click();
        check((await app.locator('[data-vto-selection-name]').textContent()).includes('گل‌های اناری'), 'Selection summary names the exact chosen artwork');
        check(await app.locator('[data-vto-submit]').isDisabled(), 'Selecting a design alone does not enable submission');
        const photo = process.env.VTO_PHOTO_FIXTURE || '/workspace/.vto-dev/portrait-fixture.png';
        await app.locator('input[type="file"]').setInputFiles(photo);
        await app.locator('[data-vto-preview]').waitFor({state:'visible'});
        check(await app.locator('[data-vto-submit]').isDisabled(), 'Customer consent remains required after photo preview');
        await app.locator('input[name="consent"]').check();
        check(await app.locator('[data-vto-submit]').isEnabled(), 'One clear action becomes ready after all required choices');
        await app.screenshot({path:path.join(artifacts,'customer-desktop.png')});
        await customer.setViewportSize({width:390,height:844});
        await app.screenshot({path:path.join(artifacts,'customer-mobile.png')});
        check(await app.evaluate(el => el.getBoundingClientRect().width <= window.innerWidth && el.scrollWidth <= el.clientWidth + 1), 'Mobile fitting room fits a 390-pixel viewport without horizontal overflow');
        await customer.setViewportSize({width:1440,height:1100});
        const queuedResponse = customer.waitForResponse(response => response.request().method() === 'POST' && /(?:vto\/v1\/jobs|rest_route=.*vto.*jobs)/.test(response.url()));
        await app.locator('[data-vto-submit]').click();
        const response = await queuedResponse;
        const queued = await response.json();
        if (queued.token) createdTokens.push(queued.token);
        check(response.status() === 202 && queued.status === 'queued' && queued.token, 'Actual customer multipart submission queues one standalone design request');
        const tracking = app.locator('[data-vto-tracking]');
        await tracking.waitFor({state:'visible'});
        check((await tracking.inputValue()).includes(queued.token), 'Private result link is visible immediately after submission');
        check(wp('process', {token:queued.token}).status === 'completed', 'Demo worker completes the same queued request without provider HTTP');
        await app.locator('[data-vto-result]').waitFor({state:'visible',timeout:15000});
        check(await app.locator('[data-vto-result]').evaluate(el => el.complete && el.naturalWidth > 0), 'Completed result is shown only after image bytes load');
        const downloadUrl = await app.locator('[data-vto-download]').getAttribute('href');
        const file = await context.request.get(downloadUrl);
        check(file.ok() && /attachment/.test(file.headers()['content-disposition'] || '') && /image\//.test(file.headers()['content-type'] || ''), 'Download action serves a private image attachment');
        await app.screenshot({path:path.join(artifacts,'customer-result-demo.png')});
        const link = await tracking.inputValue();
        await customer.goto(link);
        await customer.locator('[data-vto-result]').waitFor({state:'visible',timeout:15000});
        check(generationPosts === 1, 'Polling and reopening tracking link never submit a second generation');
        check(errors.length === 0, 'Gallery, upload and result flow have no JavaScript errors');
        process.stdout.write(`\n${checks} browser assertions passed. Generation stayed in demo; no paid API calls occurred.\nScreenshots: ${artifacts}\n`);
    } finally {
        if (browser) await browser.close();
        if (state) wp('cleanup', {...state, tokens:createdTokens});
    }
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
