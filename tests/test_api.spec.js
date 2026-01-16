const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.TEST_URL || 'https://tk2-233-26359.vs.sakura.ne.jp/kashiteapp/';
const REMOTE_API = 'https://kashite.space/wp-json/kashiteapp/v1';

test.describe('KASHITE API テスター', () => {
  test('ページが正常に読み込まれる', async ({ page }) => {
    await page.goto(BASE_URL);
    await expect(page.locator('h1')).toContainText('KASHITE API テスター');
    await expect(page.locator('#remoteBase')).toContainText('kashite.space');
  });

  test('基本テストボタンが表示される', async ({ page }) => {
    await page.goto(BASE_URL);
    // 基本テストボタンが生成されるまで待つ
    await page.waitForSelector('#basicTestButtons button', { timeout: 10000 });
    const buttons = await page.locator('#basicTestButtons button').count();
    expect(buttons).toBeGreaterThan(0);
  });

  test('ニュース（news）が取得できる', async ({ page }) => {
    await page.goto(BASE_URL);
    // newsが取得されるまで待つ
    await page.waitForFunction(
      () => {
        const newsResult = document.querySelector('#news-result');
        return newsResult && !newsResult.textContent.includes('取得中');
      },
      { timeout: 15000 }
    );
    const newsContent = await page.locator('#news-result').textContent();
    expect(newsContent).not.toContain('取得中');
  });

  test('会場タイプ（space_type）が取得できる', async ({ page }) => {
    await page.goto(BASE_URL);
    await page.waitForSelector('#filter-space-type input[type="checkbox"]', { timeout: 10000 });
    const checkboxes = await page.locator('#filter-space-type input[type="checkbox"]').count();
    expect(checkboxes).toBeGreaterThan(0);
  });

  test('利用目的（space_use）が取得できる', async ({ page }) => {
    await page.goto(BASE_URL);
    await page.waitForSelector('#filter-space-use input[type="checkbox"]', { timeout: 10000 });
    const checkboxes = await page.locator('#filter-space-use input[type="checkbox"]').count();
    expect(checkboxes).toBeGreaterThan(0);
  });

  test('エリア（space_area）が取得できる', async ({ page }) => {
    await page.goto(BASE_URL);
    await page.waitForSelector('#filter-space-area input[type="checkbox"]', { timeout: 10000 });
    const checkboxes = await page.locator('#filter-space-area input[type="checkbox"]').count();
    expect(checkboxes).toBeGreaterThan(0);
  });

  test('料金レンジが取得できる', async ({ page }) => {
    await page.goto(BASE_URL);
    await page.waitForSelector('#priceRangeArea input[type="checkbox"]', { timeout: 10000 });
    const checkboxes = await page.locator('#priceRangeArea input[type="checkbox"]').count();
    expect(checkboxes).toBeGreaterThan(0);
  });

  test('基本テストボタン（/）をクリックして結果が表示される', async ({ page }) => {
    await page.goto(BASE_URL);
    await page.waitForSelector('#basicTestButtons button[data-api="/"]', { timeout: 10000 });
    
    await page.click('#basicTestButtons button[data-api="/"]');
    
    // レスポンスが表示されるまで待つ
    await page.waitForFunction(
      () => {
        const resJson = document.querySelector('#resJson');
        return resJson && resJson.textContent !== '(レスポンス JSON)';
      },
      { timeout: 15000 }
    );
    
    const response = await page.locator('#resJson').textContent();
    expect(response).not.toBe('(レスポンス JSON)');
  });

  test('検索条件から /search_url を生成できる', async ({ page }) => {
    await page.goto(BASE_URL);
    
    // キーワードを入力
    await page.fill('#keywordInput', 'テスト');
    
    // 検索URLを生成
    await page.click('#btn-generate-search-url');
    
    // レスポンスが返るまで待つ
    await page.waitForFunction(
      () => {
        const resJson = document.querySelector('#resJson');
        return resJson && resJson.textContent.includes('url');
      },
      { timeout: 15000 }
    );
    
    const response = await page.locator('#resJson').textContent();
    expect(response).toContain('url');
  });

  test('条件クリアボタンが機能する', async ({ page }) => {
    await page.goto(BASE_URL);
    
    // キーワードを入力
    await page.fill('#keywordInput', 'テスト');
    const beforeClear = await page.inputValue('#keywordInput');
    expect(beforeClear).toBe('テスト');
    
    // 条件クリア
    await page.click('#btn-clear-conditions');
    
    const afterClear = await page.inputValue('#keywordInput');
    expect(afterClear).toBe('');
  });

  test('レスポンスコピーボタンが存在する', async ({ page }) => {
    await page.goto(BASE_URL);
    await expect(page.locator('#btn-copy-response')).toBeVisible();
  });
});

test.describe('API直接テスト（REMOTE）', () => {
  test('APIルートエンドポイントが応答する', async ({ request }) => {
    const response = await request.get(`${REMOTE_API}/`);
    expect(response.ok()).toBeTruthy();
    const data = await response.json();
    expect(data).toHaveProperty('routes');
  });

  test('/ping エンドポイントが応答する', async ({ request }) => {
    const response = await request.get(`${REMOTE_API}/ping`);
    expect(response.ok()).toBeTruthy();
  });

  test('/news エンドポイントが応答する', async ({ request }) => {
    const response = await request.get(`${REMOTE_API}/news`);
    expect(response.ok()).toBeTruthy();
    const data = await response.json();
    expect(Array.isArray(data)).toBeTruthy();
  });

  test('/option_space_type エンドポイントが応答する', async ({ request }) => {
    const response = await request.get(`${REMOTE_API}/option_space_type`);
    expect(response.ok()).toBeTruthy();
    const data = await response.json();
    expect(Array.isArray(data)).toBeTruthy();
  });

  test('/option_space_use エンドポイントが応答する', async ({ request }) => {
    const response = await request.get(`${REMOTE_API}/option_space_use`);
    expect(response.ok()).toBeTruthy();
    const data = await response.json();
    expect(Array.isArray(data)).toBeTruthy();
  });

  test('/option_space_area エンドポイントが応答する', async ({ request }) => {
    const response = await request.get(`${REMOTE_API}/option_space_area`);
    expect(response.ok()).toBeTruthy();
    const data = await response.json();
    expect(Array.isArray(data)).toBeTruthy();
  });

  test('/price_range エンドポイントが応答する', async ({ request }) => {
    const response = await request.get(`${REMOTE_API}/price_range`);
    expect(response.ok()).toBeTruthy();
    const data = await response.json();
    expect(Array.isArray(data)).toBeTruthy();
  });

  test('/filters エンドポイントが応答する', async ({ request }) => {
    const response = await request.get(`${REMOTE_API}/filters`);
    expect(response.ok()).toBeTruthy();
    const data = await response.json();
    expect(data).toBeDefined();
  });
});
