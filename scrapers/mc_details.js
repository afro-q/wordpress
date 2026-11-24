const { chromium } = require('/home/quinlan/local_source/kitnetic/inpulse/inpulse-automation/node_modules/playwright-core');
const fs = require('fs');

(async () => {
  const entries = JSON.parse(fs.readFileSync('/tmp/mc-window.json', 'utf8'));
  const browser = await chromium.launch({
    executablePath: '/home/quinlan/.cache/ms-playwright/chromium-1208/chrome-linux64/chrome',
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });
  const context = await browser.newContext({
    userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    viewport: { width: 1280, height: 900 },
  });
  const page = await context.newPage();

  const out = [];
  for (let i = 0; i < entries.length; i++) {
    const e = entries[i];
    const url = e.detail_url.replace(/\?ref=29.*$/, '');
    let rec = { ...e };
    try {
      await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
      await page.waitForTimeout(i === 0 ? 9000 : 1500);

      // JSON-LD MusicEvent
      const ld = await page.$$eval('script[type="application/ld+json"]', (els) =>
        els.map(el => { try { return JSON.parse(el.textContent); } catch (_) { return null; } }).filter(Boolean)
      );
      const ev = ld.find(o => o['@type'] === 'MusicEvent');
      if (ev) {
        rec.description = ev.description || rec.desc;
        if (ev.startDate) rec.start_ymd = String(ev.startDate).slice(0, 10);
        if (ev.endDate) rec.end_ymd = String(ev.endDate).slice(0, 10);
        if (ev.location && ev.location.name) rec.location = ev.location.name;
      }

      // goto-url (official website redirect link)
      const goto = await page.$eval('a[href*="/goto-url"]', a => a.getAttribute('href')).catch(() => null);
      if (goto) {
        const abs = new URL(goto, url).href;
        try {
          const resp = await context.request.get(abs, { maxRedirects: 10, timeout: 20000 });
          const finalUrl = resp.url();
          rec.official_url = (finalUrl && !finalUrl.includes('musicalchairs.info')) ? finalUrl : '';
        } catch (_) { rec.official_url = ''; }
      } else {
        rec.official_url = '';
      }

      out.push(rec);
      console.log(`[${i+1}/${entries.length}] ${rec.name.slice(0,45).padEnd(45)} | official=${rec.official_url ? 'yes' : 'NO'}`);
    } catch (err) {
      rec.error = String(err.message || err).slice(0, 120);
      out.push(rec);
      console.log(`[${i+1}/${entries.length}] ERROR ${rec.name.slice(0,40)}: ${rec.error}`);
    }
  }

  fs.writeFileSync('/tmp/mc-details.json', JSON.stringify(out, null, 2));
  console.log('\nDONE. wrote /tmp/mc-details.json with', out.length, 'records');
  await browser.close();
})().catch(e => { console.error('FATAL', e); process.exit(1); });
