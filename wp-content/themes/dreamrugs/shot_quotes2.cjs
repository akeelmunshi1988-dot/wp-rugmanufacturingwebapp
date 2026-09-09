const { chromium } = require('playwright');
(async () => {
  const browser = await chromium.launch();
  const context = await browser.newContext();
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', err => errors.push('pageerror: ' + err.message));
  page.on('response', async (res) => {
    if (res.url().includes('/wp-json/dreamrugs/v1/quotes')) {
      console.log('quotes API:', res.status(), await res.text().catch(()=>'(no body)'));
    }
  });

  await page.goto('http://localhost:8080/wp-login.php', { waitUntil: 'networkidle' });
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'admin_dev_password');
  await page.click('#wp-submit');
  await page.waitForTimeout(1000);

  await page.goto('http://localhost:8080/my-quotes/', { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(1500);
  const dir = '/private/tmp/claude-501/-Applications-RugManufactureCustomApp/2e38267c-c4f2-428d-888e-6fba96cb8e77/scratchpad';
  await page.screenshot({ path: dir + '/wp-my-quotes-debug.png', fullPage: true });
  console.log('ERRORS:', JSON.stringify(errors));
  await browser.close();
})();
