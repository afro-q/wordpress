const { chromium } = require('/home/quinlan/local_source/kitnetic/inpulse/inpulse-automation/node_modules/playwright-core');
const fs = require('fs');

(async () => {
  const url = 'https://www.musicalchairs.info/piano/competitions';
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
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForTimeout(9000); // Anubis PoW + redirect

  // Extract entries from <li class="preview" data-post-preview-id="...">
  const entries = await page.$$eval('li.preview[data-post-preview-id]', (lis) => lis.map((li) => {
    const id = li.getAttribute('data-post-preview-id') || '';
    const a = li.querySelector('a[href*="/competitions/"]');
    const name = li.querySelector('.post_item_name');
    const loc = li.querySelector('.post_item_location');
    const info = li.querySelector('.post_item_info');
    const desc = li.querySelector('.post_item_desc');
    const closing = li.querySelector('.post_item_closingdate');
    return {
      post_id: id,
      detail_url: a ? a.getAttribute('href') : '',
      name: name ? name.textContent.trim() : '',
      location: loc ? loc.textContent.trim() : '',
      info: info ? info.textContent.trim() : '',
      desc: desc ? desc.textContent.trim() : '',
      closing: closing ? closing.textContent.trim() : '',
    };
  }));

  fs.writeFileSync('/tmp/mc-entries.json', JSON.stringify(entries, null, 2));
  console.log('ENTRIES:', entries.length);
  console.log(JSON.stringify(entries.slice(0, 3), null, 2));
  await browser.close();
})().catch(e => { console.error('ERR', e); process.exit(1); });
