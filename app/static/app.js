// -----------------------
// 定数
// -----------------------
const REMOTE_BASE = "https://kashite.space/wp-json/kashiteapp/v1";
//
// enquiry endpoints（デフォルト）
let ENQUIRY_PREVIEW_EP = "/enquiry/preview";
let ENQUIRY_SEND_EP    = "/enquiry";


let lastEnquiryPayloadHash = "";

// 料金レンジの基準値（theoretical_min / max）
let priceBaseMin = null;
let priceBaseMax = null;

// -----------------------
// 予約UI: state（コンテナごと）
// -----------------------
const bookingStateMap = new WeakMap();

function getBookingState(container) {
  return bookingStateMap.get(container) || null;
}

function setBookingState(container, state) {
  bookingStateMap.set(container, state);
  return state;
}

// -----------------------
// 基本テスト生成
// -----------------------
function isSimpleGetNoArgs(routeObj) {
  const methods = routeObj?.methods || [];
  if (!methods.includes("GET")) return false;

  const ep = routeObj?.endpoints?.[0];
  const args = ep?.args || {};
  return Object.keys(args).length === 0;
}

function isUsablePath(path) {
  // /kashiteapp/v1 を / に寄せる
  if (path === "/kashiteapp/v1" || path === "/kashiteapp/v1/") return true;
  if (path.includes("(?P<")) return false;        // 正規表現ルートは除外
  if (path.endsWith("/cart/bridge")) return false; // ブラウザ遷移系は基本テストから外すのが無難
  return true;
}

function normalizePath(path) {
  return path.replace(/^\/kashiteapp\/v1/, "") || "/";
}

function renderBasicTestButtons(indexJson) {
  const box = document.getElementById("basicTestButtons");
  if (!box) return;

  const routes = indexJson?.routes || {};
  const keys = Object.keys(routes);

  box.innerHTML = "";
  keys
    .filter(isUsablePath)
    .map((k) => ({ raw: k, obj: routes[k], p: normalizePath(k) }))
    .filter(({ obj }) => isSimpleGetNoArgs(obj))
    .sort((a, b) => a.p.localeCompare(b.p))
    .forEach(({ p }) => {
      const btn = document.createElement("button");
      btn.className = p === "/" ? "btn btn-primary" : "btn";
      btn.type = "button";
      btn.dataset.api = p;
      btn.textContent = p;
      box.appendChild(btn);
    });
}

// -----------------------
// ユーティリティ
// -----------------------
function logLine(message) {
  const ul = document.getElementById("logList");
  const li = document.createElement("li");
  const time = new Date().toLocaleTimeString("ja-JP", { hour12: false });
  li.textContent = `[${time}] ${message}`;
  ul.prepend(li);
}

function setRequestPath(path) {
  document.getElementById("reqPath").textContent = path;
}

function setResponseJson(obj) {
  const pre = document.getElementById("resJson");
  pre.textContent = JSON.stringify(obj, null, 2);
}

function formatYen(num) {
  const n = Number(num ?? 0);
  return "¥" + n.toLocaleString("ja-JP");
}

function formatNewsDate(iso) {
  if (!iso) return "";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  const y = d.getFullYear();
  const m = d.getMonth() + 1;
  const day = d.getDate();
  return `${y}年${m}月${day}日`;
}

// REMOTE_BASE を直接叩く共通関数
async function callApi(path, { query = {}, showResponse = true } = {}) {
  const url = new URL(REMOTE_BASE + path);
  Object.entries(query).forEach(([k, v]) => url.searchParams.append(k, v));

  const urlStr = url.toString();
  setRequestPath(`GET ${urlStr}`);
  logLine(`call ${path}${url.search}`);

  const res = await fetch(urlStr, { mode: "cors" });

  if (!res.ok) {
    const err = new Error(`HTTP ${res.status}`);
    logLine(String(err));
    throw err;
  }

  const json = await res.json();
  if (showResponse) {
    setResponseJson(json);
  }
  return json;
}

async function postApiForm(path, { data = {}, showResponse = true } = {}) {
  const urlStr = REMOTE_BASE + path;
  setRequestPath(`POST ${urlStr}`);
  logLine(`post(form) ${path}`);

  const body = new URLSearchParams();
  Object.entries(data).forEach(([k, v]) => body.append(k, String(v ?? "")));

  const res = await fetch(urlStr, { method: "POST", body, mode: "cors" });

  const text = await res.text();               // ★まず生テキスト
  let json = null;
  try { json = JSON.parse(text); } catch { json = { raw: text }; }

  if (showResponse) setResponseJson(json);

  if (!res.ok) {
    // ★ここで “400の理由” を投げる
    throw new Error(json?.message || json?.data?.message || json?.raw || `HTTP ${res.status}`);
  }
  if (json?.code && json.code !== "success") throw new Error(json?.message || "API error");

  return json;
}


// -----------------------
// 初期ロード
// -----------------------
async function loadInitialData() {
  try {
    const metaRes = await callApi("/meta", { showResponse: false });
    const ep = metaRes?.data?.result?.enquiry?.endpoints;
    if (ep?.preview) ENQUIRY_PREVIEW_EP = new URL(ep.preview).pathname.replace(/^\/wp-json\/kashiteapp\/v1/, "") || "/enquiry/preview";
    if (ep?.send)    ENQUIRY_SEND_EP    = new URL(ep.send).pathname.replace(/^\/wp-json\/kashiteapp\/v1/, "") || "/enquiry";

    const [
      newsRes,
      filtersRes,
      priceRes,
      typeRes,
      useRes,
      areaRes,
    ] = await Promise.all([
      callApi("/news", { showResponse: false }),
      callApi("/filters", { showResponse: false }),
      callApi("/price_range", { showResponse: false }),
      callApi("/option_space_type", { showResponse: false }),
      callApi("/option_space_use", { showResponse: false }),
      callApi("/option_space_area", { showResponse: false }),
    ]);

    const news = newsRes.data?.result ?? [];
    const filters = filtersRes.data?.result ?? {};
    const price = priceRes.data?.result ?? null;
    const spaceTypes = typeRes.data?.result ?? [];
    const spaceUses = useRes.data?.result ?? [];
    const spaceAreas = areaRes.data?.result ?? [];

    renderNews(news, "news-result");
    buildFiltersUI(filters);
    buildPriceRangeUI(price);
    buildSpaceOptionsUI(spaceTypes, spaceUses, spaceAreas);

    const indexRes = await callApi("/", { showResponse: false });
    renderBasicTestButtons(indexRes);

    logLine("初期データロード完了");
  } catch (e) {
    logLine("初期データロード失敗: " + e);
  }
}

// -----------------------
// 会場タイプ / 利用目的 / エリア（サムネ付き）
// -----------------------
function createThumbOption(
  container,
  category,
  item,
  imageclass = "thumb-option-image"
) {
  // visible が false なら描画しない
  if (item.visible === false) return;

  const label = document.createElement("label");
  label.className = "thumb-option";

  const cb = document.createElement("input");
  cb.type = "checkbox";
  cb.className = "thumb-option-checkbox";
  cb.dataset.category = category; // space_type / space_use / space_area
  cb.value = item.key;

  const inner = document.createElement("div");
  inner.className = "thumb-option-inner";

  const imgWrap = document.createElement("div");
  imgWrap.className = imageclass;

  if (item.thumbnail && item.thumbnail.src) {
    const img = document.createElement("img");
    img.src = item.thumbnail.src;
    img.alt = item.thumbnail.alt || item.label || "";
    imgWrap.appendChild(img);
  }

  const caption = document.createElement("div");
  caption.className = "thumb-option-label";
  caption.textContent = item.label;

  inner.appendChild(imgWrap);
  inner.appendChild(caption);

  label.appendChild(cb);
  label.appendChild(inner);

  container.appendChild(label);
}

function buildSpaceOptionsUI(spaceTypes, spaceUses, spaceAreas) {
  const typeBox = document.getElementById("filter-space-type");
  const useBox = document.getElementById("filter-space-use");
  const areaBox = document.getElementById("filter-space-area");

  typeBox.innerHTML = "";
  useBox.innerHTML = "";
  areaBox.innerHTML = "";

  (spaceTypes || []).forEach((item) =>
    createThumbOption(typeBox, "space_type", item)
  );

  (spaceUses || []).forEach((item) =>
    createThumbOption(useBox, "space_use", item)
  );

  (spaceAreas || []).forEach((pref) => {
    if (pref.visible === false) return;

    const prefTitle = document.createElement("div");
    prefTitle.className = "area-pref-title";
    prefTitle.textContent = pref.label;
    areaBox.appendChild(prefTitle);

    (pref.children || []).forEach((child) =>
      createThumbOption(areaBox, "space_area", child, "thumb-option-image_1_1")
    );
  });
}

// -----------------------
// フィルタ UI 生成
// -----------------------

// /filters の short_key → UI 設定（念のため残しておく）
const FILTER_CONFIG = {
  // 立地条件（地域）グループ
  city: { group: "location", param: "city" },
  "indoor-outdoor": { group: "location", param: "indoor_outdoor" },
  station: { group: "location", param: "station" },
  parking: { group: "location", param: "parking" },
  "number-people": { group: "location", param: "number_people" },
  "purpose-use": { group: "location", param: "purpose_use" },

  // 飲食
  "eating-drink": { group: "eating", param: "eating_drink" },

  // 設備
  facility: { group: "facility", param: "facility" },

  // 支払方法
  payment: { group: "payment", param: "payment" },
};

const FILTER_GROUP_META = {
  location: { title: "立地条件" },
  eating: { title: "飲食" },
  facility: { title: "設備・仕様" },
  payment: { title: "支払方法" },
};

function buildFiltersUI(filters) {
  const locContainer = document.getElementById("filter-location");
  const eatingContainer = document.getElementById("filter-eating");
  const facilityContainer = document.getElementById("filter-facility");
  const paymentContainer = document.getElementById("filter-payment");

  if (
    !locContainer ||
    !eatingContainer ||
    !facilityContainer ||
    !paymentContainer
  ) {
    logLine("filter コンテナが見つかりません");
    return;
  }

  locContainer.innerHTML = "";
  eatingContainer.innerHTML = "";
  facilityContainer.innerHTML = "";
  paymentContainer.innerHTML = "";

  const appendBlock = (container, paramName, block) => {
    if (!block || !Array.isArray(block.items)) return;

    const sub = document.createElement("div");
    sub.className = "filter-subgroup";

    const title = document.createElement("div");
    title.className = "filter-subtitle";
    title.textContent = `${block.label}（${block.key}）`;
    sub.appendChild(title);

    const grid = document.createElement("div");
    grid.className = "checkbox-grid";

    block.items.forEach((item) => {
      const label = document.createElement("label");
      label.className = "checkbox-item";

      const cb = document.createElement("input");
      cb.type = "checkbox";
      cb.dataset.category = paramName;
      cb.value = item.key;

      label.appendChild(cb);
      label.append(" " + item.label);
      grid.appendChild(label);
    });

    sub.appendChild(grid);
    container.appendChild(sub);
  };

  // 立地条件
  appendBlock(locContainer, "city", filters["city"]);
  appendBlock(locContainer, "indoor_outdoor", filters["indoor-outdoor"]);
  appendBlock(locContainer, "station", filters["station"]);
  appendBlock(locContainer, "parking", filters["parking"]);
  appendBlock(locContainer, "number_people", filters["number-people"]);
  appendBlock(locContainer, "purpose_use", filters["purpose-use"]);

  // 飲食
  appendBlock(eatingContainer, "eating_drink", filters["eating-drink"]);

  // 設備
  appendBlock(facilityContainer, "facility", filters["facility"]);

  // 支払方法
  appendBlock(paymentContainer, "payment", filters["payment"]);
}

// -----------------------
// 料金レンジ UI（2ハンドル 1 本バー）
// -----------------------
function buildPriceRangeUI(price) {
  const box = document.getElementById("priceRangeArea");
  if (!price) {
    box.textContent = "price_range の取得に失敗しました";
    return;
  }

  const min = price.theoretical_min ?? 0;
  const max = price.theoretical_max ?? 0;
  const realMin = price.real_min ?? min;
  const realMax = price.real_max ?? max;

  priceBaseMin = min;
  priceBaseMax = max;

  box.innerHTML = `
    <div class="price-slider">
      <div class="price-values">
        <div class="price-badge" id="priceMinBadge">${formatYen(realMin)}</div>
        <div class="price-badge" id="priceMaxBadge">${formatYen(realMax)}</div>
      </div>
      <div class="price-slider-track">
        <div class="price-slider-bar"></div>
        <div class="price-slider-range" id="priceSliderRange"></div>
        <input type="range" id="priceMin" min="${min}" max="${max}" value="${realMin}">
        <input type="range" id="priceMax" min="${min}" max="${max}" value="${realMax}">
      </div>
    </div>
  `;

  const minInput = document.getElementById("priceMin");
  const maxInput = document.getElementById("priceMax");
  const minBadge = document.getElementById("priceMinBadge");
  const maxBadge = document.getElementById("priceMaxBadge");
  const rangeEl = document.getElementById("priceSliderRange");

  const update = () => {
    let minVal = Number(minInput.value);
    let maxVal = Number(maxInput.value);

    if (minVal > maxVal) {
      maxVal = minVal;
      maxInput.value = String(maxVal);
    }

    const span = max - min || 1;
    const pctMin = ((minVal - min) / span) * 100;
    const pctMax = ((maxVal - min) / span) * 100;

    rangeEl.style.left = `${pctMin}%`;
    rangeEl.style.right = `${100 - pctMax}%`;

    minBadge.textContent = formatYen(minVal);
    maxBadge.textContent = formatYen(maxVal);
  };

  minInput.addEventListener("input", update);
  maxInput.addEventListener("input", update);
  update();
}

// -----------------------
// /search_url 生成
// -----------------------
async function onGenerateSearchUrl() {
  const query = {};

  const keyword = document.getElementById("keywordInput").value.trim();
  if (keyword) {
    query.keyword = keyword;
  }

  const selectedByCat = {};
  document
    .querySelectorAll('input[type="checkbox"][data-category]')
    .forEach((cb) => {
      if (!cb.checked) return;
      const cat = cb.dataset.category;
      if (!cat) return;
      if (!selectedByCat[cat]) selectedByCat[cat] = [];
      selectedByCat[cat].push(cb.value);
    });

  Object.entries(selectedByCat).forEach(([cat, values]) => {
    query[cat] = values.join(",");
  });

  const minInput = document.getElementById("priceMin");
  const maxInput = document.getElementById("priceMax");
  if (minInput && maxInput && priceBaseMin != null && priceBaseMax != null) {
    const minVal = Number(minInput.value);
    const maxVal = Number(maxInput.value);

    if (minVal > priceBaseMin || maxVal < priceBaseMax) {
      query.price_min = String(minVal);
      query.price_max = String(maxVal);
    }
  }

  const res = await callApi("/search_url", { query, showResponse: true });
  const result = res.data?.result;
  if (!result || !result.url) {
    logLine("search_url の結果に url がありません");
    return;
  }

  const input = document.getElementById("searchUrlInput");
  input.value = result.url;
  logLine("search_url 生成完了");
}

// -----------------------
// /search_results 実行
// -----------------------
async function onSearchResults() {
  const input = document.getElementById("searchUrlInput");
  const url = input.value.trim();
  if (!url) {
    alert("先に /search_url を実行して URL を入力してください");
    return;
  }

  const res = await callApi("/search_results", {
    query: { url },
    showResponse: true,
  });

  const result = res.data?.result;
  if (!result || !Array.isArray(result.items)) {
    logLine("search_results の結果に items がありません");
    renderSearchCards([]);
    return;
  }

  renderSearchCards(result.items);
}

// -----------------------
// 条件クリア
// -----------------------
function onClearConditions() {
  const keywordEl = document.getElementById("keywordInput");
  if (keywordEl) keywordEl.value = "";

  document
    .querySelectorAll(
      ".checkbox-item input[type=checkbox], .thumb-option-checkbox"
    )
    .forEach((cb) => {
      cb.checked = false;
    });

  const minInput = document.getElementById("priceMin");
  const maxInput = document.getElementById("priceMax");
  if (minInput && maxInput) {
    minInput.value = minInput.min ?? minInput.value;
    maxInput.value = maxInput.max ?? maxInput.value;
    const ev = new Event("input", { bubbles: true });
    minInput.dispatchEvent(ev);
    maxInput.dispatchEvent(ev);
  }

  const searchUrlInput = document.getElementById("searchUrlInput");
  if (searchUrlInput) searchUrlInput.value = "";

  const area = document.getElementById("searchResultsArea");
  if (area) area.innerHTML = "";

  logLine("検索条件をクリアしました");
}

// -----------------------
// 検索結果カード描画
// -----------------------
function renderSearchCards(items) {
  const area = document.getElementById("searchResultsArea");
  area.innerHTML = "";

  if (!items.length) {
    area.textContent = "検索結果がありません。";
    return;
  }

  items.forEach((item) => {
    const card = document.createElement("div");
    card.className = "result-card";
    card.dataset.productId = item.id ?? "";

    const thumb = document.createElement("img");
    thumb.className = "card-thumb";
    thumb.src = item.thumbnail || "";
    thumb.alt = item.title || "";

    const body = document.createElement("div");
    body.className = "card-body";

    const title = document.createElement("div");
    title.className = "card-title";
    title.textContent = item.title || "(no title)";

    const price = document.createElement("div");
    price.className = "card-price";
    price.innerHTML = item.price_html || "";

    const actions = document.createElement("div");
    actions.className = "card-actions";

    const detailBtn = document.createElement("button");
    detailBtn.type = "button";
    detailBtn.className = "btn btn-xs";
    detailBtn.textContent = "詳細(JSON)";
    detailBtn.addEventListener("click", () => {
      const productId = item.id;
      if (!productId) {
        logLine("この item には id がありません");
        return;
      }
      selectCard(card);
      fetchProductDetail(productId);
    });

    const link = document.createElement("a");
    link.href = item.permalink || "#";
    link.target = "_blank";
    link.rel = "noopener noreferrer";
    link.textContent = "サイトを開く";

    actions.appendChild(detailBtn);
    actions.appendChild(link);

    body.appendChild(title);
    body.appendChild(price);
    body.appendChild(actions);

    card.appendChild(thumb);
    card.appendChild(body);

    area.appendChild(card);
  });
}

function selectCard(card) {
  document
    .querySelectorAll(".result-card.selected")
    .forEach((c) => c.classList.remove("selected"));
  card.classList.add("selected");
}

// -----------------------
// 商品プレビューモーダル（縦スクロール版）
// -----------------------
function openProductModal(product) {
  renderProductModal(product);
  const modal = document.getElementById("productModal");
  if (modal) modal.classList.add("is-open");
}

function closeProductModal() {
  const modal = document.getElementById("productModal");
  if (modal) modal.classList.remove("is-open");
}

function extractImageUrlsFromHtml(html) {
  const urls = [];
  if (!html) return urls;
  const re = /<img[^>]+src=["']([^"']+)["']/g;
  let m;
  while ((m = re.exec(html)) !== null) {
    urls.push(m[1]);
  }
  return urls;
}

function renderMediaSection(root, section) {
  const urls = extractImageUrlsFromHtml(section.content || "");
  if (!urls.length) {
    root.innerHTML = section.content || "";
    return;
  }

  const main = document.createElement("div");
  main.className = "pm-main-image-wrap";
  const mainImg = document.createElement("img");
  mainImg.src = urls[0];
  main.appendChild(mainImg);

  const thumbs = document.createElement("div");
  thumbs.className = "pm-thumbs";

  urls.forEach((src, idx) => {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className =
      "pm-thumb-img-wrap" + (idx === 0 ? " is-active" : "");
    const img = document.createElement("img");
    img.src = src;
    btn.appendChild(img);

    btn.addEventListener("click", () => {
      mainImg.src = src;
      thumbs
        .querySelectorAll(".pm-thumb-img-wrap")
        .forEach((el) => el.classList.remove("is-active"));
      btn.classList.add("is-active");
    });

    thumbs.appendChild(btn);
  });

  root.innerHTML = "";
  root.appendChild(main);
  root.appendChild(thumbs);
}

function esc(s) {
  return String(s ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

function getEnquiryPayloadFromUI(root) {
  const get = (name) => root.querySelector(`[name="${name}"]`)?.value ?? "";
  return {
    product_id: Number(get("product_id") || 0),
    name: get("name").trim(),
    email: get("email").trim(),
    phone: get("phone").trim(),
    enquiry: get("enquiry").trim(),
  };
}

async function postApi(path, body, { showResponse = false } = {}) {
  const urlStr = REMOTE_BASE + path;
  setRequestPath(`POST ${urlStr}`);
  logLine(`post ${path}`);
  const res = await fetch(urlStr, {
    method: "POST",
    mode: "cors",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  const json = await res.json().catch(() => ({}));
  if (!res.ok) {
    const msg = json?.message || `HTTP ${res.status}`;
    throw new Error(msg);
  }
  if (showResponse) setResponseJson(json);
  return json;
}

function renderContactSection(root, product) {
  const contact = product.contact || {};
  const ownerEmail = contact.owner_email || "";
  const adminEmail = contact.admin_email || "";

  // 入力項目（最低限固定：preview/send が期待する項目）
  // product.contact.form.fields も使って良いが、まずは安定優先
  const pid = product.id ?? "";

  root.innerHTML = `
    <div class="enq-box">
      <p style="font-size:12px;color:#4b5563;margin:0 0 8px;">
        /enquiry/preview でメール内容を生成 → /enquiry で送信します。
      </p>

      <div class="enq-grid">
        <label>product_id</label>
        <input name="product_id" type="number" value="${esc(pid)}">

        <label>お名前</label>
        <input name="name" type="text" placeholder="テスト太郎" value="テスト太郎（返信不要）">

        <label>メール</label>
        <input name="email" type="text" placeholder="test@example.com" value="yuki_sato@ars.sc">

        <label>電話</label>
        <input name="phone" type="text" placeholder="090-0000-0000" value="080-1111-2222">

        <label>内容</label>
        <textarea name="enquiry" rows="4" placeholder="お問い合わせ内容">KASHITE API テスター</textarea>
      </div>

      <div style="display:flex;gap:8px;margin-top:10px;align-items:center;flex-wrap:wrap;">
        <button type="button" class="btn btn-primary btn-enq-preview">プレビュー</button>
        <button type="button" class="btn btn-enq-send" disabled>送信</button>

        <span style="margin-left:auto;font-size:12px;color:#6b7280;">
          payload_hash: <code class="enq-hash">(none)</code>
        </span>
      </div>

      <details style="margin-top:10px;">
        <summary>Preview</summary>
        <div class="enq-preview-mini" style="margin-top:8px;"></div>
        <pre class="enq-preview-json" style="white-space:pre-wrap;"></pre>
      </details>

      <details style="margin-top:10px;">
        <summary>Send</summary>
        <pre class="enq-send-json" style="white-space:pre-wrap;"></pre>
      </details>

      <p style="margin-top:10px;font-size:12px;color:#6b7280;">
        宛先候補: ${ownerEmail ? `<code>${esc(ownerEmail)}</code>` : "-"}
        ${adminEmail ? ` / 管理者: <code>${esc(adminEmail)}</code>` : ""}
      </p>
    </div>
  `;

  const btnPreview = root.querySelector(".btn-enq-preview");
  const btnSend = root.querySelector(".btn-enq-send");
  const hashEl = root.querySelector(".enq-hash");
  const previewMini = root.querySelector(".enq-preview-mini");
  const previewJsonEl = root.querySelector(".enq-preview-json");
  const sendJsonEl = root.querySelector(".enq-send-json");

  // ★ previewで必要なのは issued_at と payload_hash だけ
  let lastPreview = null; // { issued_at:number, payload_hash:string }

  btnPreview.addEventListener("click", async () => {
    try {
      btnPreview.disabled = true;
      btnSend.disabled = true;

      previewMini.innerHTML = "";
      previewJsonEl.textContent = "loading...";
      sendJsonEl.textContent = "";
      hashEl.textContent = "(none)";
      lastPreview = null;

      const form = getEnquiryPayloadFromUI(root);
      const json = await postApi(ENQUIRY_PREVIEW_EP, form, { showResponse: true });

      previewJsonEl.textContent = JSON.stringify(json, null, 2);

      const r = json?.data?.result || {};
      const issued_at = Number(r.issued_at || 0);
      const payload_hash = String(r.payload_hash || "");

      lastPreview = { issued_at, payload_hash };
      hashEl.textContent = payload_hash || "(none)";

      // 目視用（to/cc/subject）
      const p = r?.payload || {};
      const to = p?.to || "";
      const cc = p?.cc || "";
      const subject = p?.subject || "";
      previewMini.innerHTML = `
        <div style="font-size:12px;line-height:1.6;">
          <div><b>To:</b> <code>${esc(to)}</code></div>
          <div><b>Cc:</b> <code>${esc(cc)}</code></div>
          <div><b>Subject:</b> ${esc(subject)}</div>
        </div>
      `;

      if (issued_at && payload_hash) btnSend.disabled = false;
      logLine("enquiry preview OK");
    } catch (e) {
      previewJsonEl.textContent = "preview error: " + (e?.message || e);
      logLine("enquiry preview NG: " + e);
    } finally {
      btnPreview.disabled = false;
    }
  });

  btnSend.addEventListener("click", async () => {
    try {
      btnSend.disabled = true;
      sendJsonEl.textContent = "loading...";

      if (!lastPreview?.issued_at || !lastPreview?.payload_hash) {
        throw new Error("preview結果がありません（先にプレビュー）");
      }

      const form = getEnquiryPayloadFromUI(root);

      // ★ 正式仕様：フォーム + issued_at + payload_hash を送る
      const json = await postApi(
        ENQUIRY_SEND_EP,
        {
          ...form,
          issued_at: Number(lastPreview.issued_at),
          payload_hash: String(lastPreview.payload_hash),
        },
        { showResponse: true }
      );

      sendJsonEl.textContent = JSON.stringify(json, null, 2);
      logLine("enquiry send OK");
    } catch (e) {
      sendJsonEl.textContent = "send error: " + (e?.message || e);
      logLine("enquiry send NG: " + e);
    } finally {
      btnSend.disabled = false;
    }
  });

}


// 追加：/cart/bridge URL を作る
function buildCartBridgeUrl({ calendar_id, date, start_hour, end_hour }) {
  const url = new URL(REMOTE_BASE + "/cart/bridge");
  url.searchParams.set("calendar_id", String(calendar_id));
  url.searchParams.set("date", date);
  url.searchParams.set("start_hour", start_hour);
  url.searchParams.set("end_hour", end_hour);
  return url.toString();
}


// "HH:MM" -> minutes
function timeToMinutes(hm) {
  const [h, m] = String(hm || "").split(":").map(Number);
  if (!Number.isFinite(h) || !Number.isFinite(m)) return null;
  return h * 60 + m;
}

function normalizeRange(a, b) {
  const am = timeToMinutes(a);
  const bm = timeToMinutes(b);
  if (am == null || bm == null) return null;
  return am <= bm
    ? { start: a, end: b }
    : { start: b, end: a };
}

function clearSlotClasses(container) {
  container.querySelectorAll(".kb-slot").forEach((el) => {
    el.classList.remove(
      "is-selected",
      "is-range",
      "is-range-start",
      "is-range-end"
    );
  });
}

function applySelectionUI(container, date, start, end, mode) {
  clearSlotClasses(container);
  if (!date || !start || !end) return;

  if (mode === "single") {
    const btn = container.querySelector(
      `.kb-slot.is-available[data-date="${date}"][data-time-start="${start}"]`
    );
    if (btn) btn.classList.add("is-selected");
    return;
  }

  const r = normalizeRange(start, end);
  if (!r) return;

  const sMin = timeToMinutes(r.start);
  const eMin = timeToMinutes(r.end);
  if (sMin == null || eMin == null) return;

  const slots = Array.from(
    container.querySelectorAll(".kb-slot.is-available")
  ).filter((b) => b.dataset.date === date);

  slots.forEach((b) => {
    const bm = timeToMinutes(b.dataset.timeStart);
    if (bm != null && bm >= sMin && bm <= eMin) {
      b.classList.add("is-range");
    }
  });

  const startBtn = slots.find(b => b.dataset.timeStart === r.start);
  const endBtn   = slots.find(b => b.dataset.timeStart === r.end);
  if (startBtn) startBtn.classList.add("is-range-start");
  if (endBtn)   endBtn.classList.add("is-range-end");
}

function writeCartBridgeInputs(calendarId, date, start, end) {
  const cId = document.getElementById("cartCalendarIdInput");
  const cDate = document.getElementById("cartDateInput");
  const cStart = document.getElementById("cartStartHourInput");
  const cEnd = document.getElementById("cartEndHourInput");

  if (cId) cId.value = String(calendarId || "");
  if (cDate) cDate.value = String(date || "");
  if (cStart) cStart.value = String(start || "");
  if (cEnd) cEnd.value = String(end || "");
}

function setupSlotClickHandlers(container, slots) {
  const buttons = container.querySelectorAll(".kb-slot.is-available");
  if (!buttons.length) return;

  const calendarId = Number(container.dataset.calendarId || 0);

  buttons.forEach((btn) => {
    btn.addEventListener("click", () => {
      const date  = btn.dataset.date;
      const start = btn.dataset.timeStart; // "HH:MM"
      const end   = btn.dataset.timeEnd;   // "HH:MM"

      const state = getBookingState(container);

      // -------------------------
      // 1) 単一選択：同じ枠を再クリック → 解除
      // -------------------------
      if (
        state &&
        state.mode === "single" &&
        state.date === date &&
        state.start_hour === start
      ) {
        clearBookingState(container);
        clearSlotClasses(container);
        writeCartBridgeInputs("", "", "", "");
        return;
      }

      // -------------------------
      // 2) 範囲選択中：範囲内クリック → 解除
      // -------------------------
      if (state && state.mode === "range" && state.date === date) {
        const r = normalizeRange(state.start_hour, state.end_hour);
        const c = timeToMinutes(start);
        if (r && c != null) {
          const s = timeToMinutes(r.start);
          const e = timeToMinutes(r.end);
          if (c >= s && c <= e) {
            clearBookingState(container);
            clearSlotClasses(container);
            writeCartBridgeInputs("", "", "", "");
            return;
          }
        }
      }

      // -------------------------
      // 3) 単一 → 2回目クリックで範囲確定
      // -------------------------
      if (state && state.mode === "single" && state.date === date) {
        const r2 = normalizeRange(state.start_hour, start);
        if (!r2) return;

        // end_hour は「終点の timeEnd」を採用（逆方向クリックにも対応）
        const finalEnd =
          timeToMinutes(start) >= timeToMinutes(state.start_hour)
            ? end
            : state.end_hour;

        setBookingState(container, {
          mode: "range",
          calendar_id: calendarId,
          date,
          start_hour: r2.start,
          end_hour: finalEnd,
        });

        applySelectionUI(container, date, r2.start, r2.end, "range");
        writeCartBridgeInputs(calendarId, date, r2.start, finalEnd);
        return;
      }

      // -------------------------
      // 4) 初回クリック：単一選択として保存
      // -------------------------
      setBookingState(container, {
        mode: "single",
        calendar_id: calendarId,
        date,
        start_hour: start,
        end_hour: end,
      });

      applySelectionUI(container, date, start, end, "single");
      writeCartBridgeInputs(calendarId, date, start, end);
    });
  });
}

// カレンダーAPI結果から予約セクションを描画
function renderBookingSection(container, calendar) {
  if (!container || !calendar) return;

  const rawDays = calendar.days || {};
  const slots   = Array.isArray(calendar.slots) ? calendar.slots : [];

  // --- days と slots の OR を取る --------------------
  // rawDays をコピーしつつ、slots にだけある日付を補完する
  const days = { ...rawDays };

  slots.forEach((s) => {
    if (!s.date) return;

    const hasAvail =
      s.status === "available" && Number(s.available || 0) > 0;

    if (!days[s.date]) {
      // pbs 側に出てこない「全部予約済みの日」用
      days[s.date] = {
        date_start: `${s.date} ${s.time_start || "00:00:00"}`,
        date_end:   `${s.date} ${s.time_end   || "23:59:59"}`,
        status: hasAvail ? "available" : "booked",
      };
    } else {
      // pbs 側では「休業日扱い」でも、slots に空きがあれば available に昇格
      if (
        days[s.date].status !== "available" &&
        hasAvail
      ) {
        days[s.date].status = "available";
      }
    }
  });

  const dayKeys = Object.keys(days).sort(); // "2025-12-16" など

  // 選択中日付は data-selected-date に保持、
  // なければ「予約可の最初の日」→ なければ先頭の日
  let selectedDate = container.dataset.selectedDate;
  if (!selectedDate || !days[selectedDate]) {
    selectedDate =
      dayKeys.find((d) => days[d].status === "available") ||
      dayKeys[0] ||
      null;
    container.dataset.selectedDate = selectedDate || "";
  }

  // 日付ボタン群
  const daysHtml =
    dayKeys.length === 0
      ? '<p>予約可能な日程はありません。</p>'
      : dayKeys
          .map((date) => {
            const day = days[date];
            const classes = ["kb-day"];
            if (date === selectedDate) classes.push("is-selected");
            if (day.status !== "available") classes.push("is-disabled");

            return `
              <button
                type="button"
                class="${classes.join(" ")}"
                data-date="${date}"
              >
                <span class="kb-day-date">${formatDateJP(date)}</span>
                <span class="kb-day-status">
		  ${day.mark ? day.mark : (day.status === "available" ? "◯" : "×")}
                </span>
              </button>
            `;
          })
          .join("");

  // スロット一覧
  const slotsHtml = buildSlotsHtml(slots, selectedDate);

  container.innerHTML = `
    <div class="kb-days">
      ${daysHtml}
    </div>
    <div class="kb-slots">
      ${slotsHtml}
    </div>
  `;

  // ▼ ここで一度クリックハンドラを張る
  setupSlotClickHandlers(container, slots);

  // 日付クリックでスロットのみ差し替え
  container.querySelectorAll(".kb-day").forEach((btn) => {
    btn.addEventListener("click", () => {
      const date = btn.dataset.date;
      container.dataset.selectedDate = date;

      container.querySelectorAll(".kb-day").forEach((b) => {
        b.classList.toggle("is-selected", b.dataset.date === date);
      });

      const slotsContainer = container.querySelector(".kb-slots");
      slotsContainer.innerHTML = buildSlotsHtml(slots, date);


      // ▼ 切り替え後も毎回ハンドラを張り直す
      setupSlotClickHandlers(container, slots);
    });
  });
}

// 指定日のスロットHTMLを作る
// 指定日のスロットHTMLを作る
function buildSlotsHtml(slots, date) {
  if (!date) return '<p>日付を選択してください。</p>';

  // その日の slot を取得
  const daySlotsAll = slots.filter(s => s.date === date);

  // gap は UI に出さない
  const daySlots = daySlotsAll.filter(s => s.status !== 'gap');

  // そもそも slot が無い
  if (!daySlotsAll.length) {
    return '<p>この日は予約枠がありません。</p>';
  }

  // slot はあるが、全部 gap（＝予約の対象にならない）
  if (!daySlots.length) {
    return '<p>この日は予約できる時間帯がありません。</p>';
  }

  const body = daySlots.map(s => {
    const availRaw = s.available;
    const hasStock = (availRaw == null) ? true : Number(availRaw) > 0;
    const isAvailable = s.status === 'available' && hasStock;
    const isBooked    = s.status === 'booked' || !isAvailable;

    const statusLabel = isAvailable ? '予約可' : '予約済み';
    const priceLabel  = s.price > 0 ? `${s.price.toLocaleString()}円` : '―';
    const promoBadge  = s.promo ? '<span class="kb-slot-promo">キャンペーン</span>' : '';
    const disabled    = isAvailable ? '' : ' disabled aria-disabled="true"';

    const classes = ['kb-slot'];
    classes.push(isAvailable ? 'is-available' : 'is-booked');

    return `
      <button
        type="button"
        class="${classes.join(' ')}"
        data-date="${s.date}"
        data-time-start="${s.time_start}"
        data-time-end="${s.time_end}"
        ${disabled}
      >
        <span class="kb-slot-time">${s.time_start}〜${s.time_end}</span>
        <span class="kb-slot-price">${priceLabel}</span>
        <span class="kb-slot-status">${statusLabel}</span>
        ${promoBadge}
      </button>
    `;
  }).join('');

  return `
    <div class="kb-slots-list">
      ${body}
      <p class="kb-slots-note">
        ※ 時間帯をクリックすると選択状態になります（デモ表示。ここから本予約画面に飛ばす想定）。
      </p>
    </div>
  `;
}

// "2025-12-16" -> "12/16(火)" 的なやつ
function formatDateJP(isoDate) {
  const [y, m, d] = isoDate.split('-').map(Number);
  const dt = new Date(y, m - 1, d);
  const w = '日月火水木金土'.charAt(dt.getDay());
  return `${m}/${d}(${w})`;
}

// product JSON → モーダル描画
function renderProductModal(product) {
  const titleEl     = document.getElementById("pmTitle");
  const priceEl     = document.getElementById("pmPrice");
  const linkEl      = document.getElementById("pmLink");
  const mainImg     = document.getElementById("pmMainImage");
  const thumbs      = document.getElementById("pmThumbs");
  const shortDesc   = document.getElementById("pmShortDesc");
  const sectionsRoot= document.getElementById("pmSections");

  // ---- ヘッダー部分 ----
  titleEl.textContent = product.name || "";
  priceEl.textContent =
    product.price != null ? formatYen(product.price) + " / 時間" : "";
  if (linkEl) {
    linkEl.href = product.permalink || "#";
  }

  // メイン画像＋サムネ
  const images = product.images || [];
  thumbs.innerHTML = "";
  if (images.length > 0) {
    mainImg.src = images[0].src;
    mainImg.alt = images[0].alt || product.name || "";

    images.forEach((img, index) => {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className =
        "pm-thumb-img-wrap" + (index === 0 ? " is-active" : "");
      const imgEl = document.createElement("img");
      imgEl.src = img.src;
      imgEl.alt = img.alt || "";
      btn.appendChild(imgEl);

      btn.addEventListener("click", () => {
        mainImg.src = img.src;
        mainImg.alt = img.alt || product.name || "";
        thumbs
          .querySelectorAll(".pm-thumb-img-wrap")
          .forEach((el) => el.classList.remove("is-active"));
        btn.classList.add("is-active");
      });

      thumbs.appendChild(btn);
    });
  } else {
    mainImg.src = "";
    mainImg.alt = "";
  }

  // short description
  shortDesc.innerHTML = product.short_description_html || "";

  // ---- 下側セクションを縦積み ----
  sectionsRoot.innerHTML = "";

  // 共通フォーマットの sections 配列を組み立てる
  const sections = [];

  // 先頭に「概要」（description_html）
  if (product.description_html) {
    sections.push({
      id: "overview",
      title: "概要",
      type: "html",
      content: product.description_html || "",
    });
  }

  if (Array.isArray(product.sections) && product.sections.length > 0) {
    // 新 API: sections が来ている場合はこちらを優先
    product.sections.forEach((sec, idx) => {
      const rawId = sec.id || `sec-${idx}`;
      if (rawId === "overview") return; // 二重登録は避ける
      sections.push({
        id: rawId,
        title: sec.title || rawId,
        type: sec.type || "html",
        content: sec.content || "",
        calendar_id: sec.calendar_id || null,
      });
    });
  } else if (Array.isArray(product.tabs) && product.tabs.length > 0) {
    // 旧 yikes タブ形式 → sections に変換
    product.tabs.forEach((t, idx) => {
      const rawId = t.id || `tab-${idx}`;
      const title = t.title || rawId;
      let id   = rawId;
      let type = "html";

      // 「概要」タブは description_html に含めているのでスキップ
      if (title === "概要") return;

      // 写真・ビデオ → カルーセル
      if (
        rawId === "写真・ビデオ" ||
        title.indexOf("写真") !== -1 ||
        title.indexOf("ビデオ") !== -1
      ) {
        id   = "media";
        type = "media";
      }
      // 予約カレンダー → booking_calendar セクション
      else if (
        title.indexOf("予約カレンダー") !== -1 ||
        rawId === "booking"
      ) {
        type = "booking_calendar";
      }
      // お問い合わせ → contact_form セクション
      else if (
        title.indexOf("お問い合わせ") !== -1 ||
        title.indexOf("問合せ") !== -1 ||
        rawId === "contact"
      ) {
        type = "contact_form";
      }

      sections.push({
        id,
        title,
        type,
        content: t.content || "",
      });
    });
  }

  // 最後の保険（description も tabs も空のとき）
  if (!sections.length) {
    sections.push({
      id: "overview",
      title: "概要",
      type: "html",
      content: product.description_html || "",
    });
  }

  // ---- 実際に描画 ----
  sections.forEach((sec) => {
    const secEl = document.createElement("section");
    secEl.className = "pm-section";

    // 見出し
    const titleEl = document.createElement("h3");
    titleEl.className = "pm-section-title";
    titleEl.textContent = sec.title || sec.id || "セクション";
    secEl.appendChild(titleEl);

    const body = document.createElement("div");
    secEl.appendChild(body);

    switch (sec.type) {
      case "booking_calendar":
        const cal = product.calendar || {};
        if (cal && cal.has_calendar && cal.calendar_id) {
          const div = document.createElement("div");
          div.className = "kashite-booking";
          div.dataset.kashiteCalendar = "1";
          div.dataset.calendarId = String(cal.calendar_id);
          body.appendChild(div);

          // このセクション配下だけカレンダー初期化
          initKashiteCalendars(body);
        } else {
          body.textContent = "このスペースには予約カレンダーがありません。";
        }
        break;
      case "contact_form":
        renderContactSection(body, product);
        break;
      case "media":
        // 写真・ビデオ → カルーセル
        renderMediaSection(body, sec);
        break;
      default:
        body.innerHTML = sec.content || "";
        break;
    }

    sectionsRoot.appendChild(secEl);
  });
}

// -----------------------
// product/{id} 詳細取得
// -----------------------
async function fetchProductDetail(productId) {
  try {
    const res = await callApi(`/product/${productId}`, {
      showResponse: true,
    });

    const product = res.data?.result;
    if (!product) {
      logLine("product の result がありません");
      return;
    }

    logLine(`product ${productId} 取得完了: ${product.name ?? ""}`);

    // カレンダーIDは従来通り右側パネルへ
    const cal = product.calendar;
    if (cal && cal.has_calendar && cal.calendar_id) {
      const input = document.getElementById("calendarIdInput");
      if (input) {
        input.value = String(cal.calendar_id);
      }
      logLine(`calendar_id=${cal.calendar_id} をセット`);
    }

    openProductModal(product);
  } catch (e) {
    logLine("product 取得失敗: " + e);
  }
}

// -----------------------
// カレンダー API
// -----------------------
async function callCalendar() {
  const idInput    = document.getElementById("calendarIdInput");
  const startInput = document.getElementById("calendarStartInput");
  const endInput   = document.getElementById("calendarEndInput");

  const id = idInput.value.trim();
  if (!id) {
    alert("calendar_id を入力してください（product API から自動入力される場合あり）");
    return;
  }

  const start = startInput ? startInput.value.trim() : "";
  const end   = endInput ? endInput.value.trim() : "";

  // ベースパス
  let path = `/calendar/${encodeURIComponent(id)}`;

  // start / end が指定されていればクエリを付ける
  const params = new URLSearchParams();
  if (start) params.append("start", start);
  if (end)   params.append("end", end);

  if ([...params].length > 0) {
    path += `?${params.toString()}`;
  }

  await callApi(path, { showResponse: true });
}

// -----------------------
// レスポンス JSON コピー
// -----------------------
async function copyResponseJson() {
  const pre = document.getElementById("resJson");
  if (!pre) return;

  const text = pre.textContent || "";

  try {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(text);
    } else {
      const ta = document.createElement("textarea");
      ta.value = text;
      ta.style.position = "fixed";
      ta.style.top = "-9999px";
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      document.execCommand("copy");
      document.body.removeChild(ta);
    }
    logLine("レスポンス JSON をクリップボードにコピーしました");
  } catch (e) {
    logLine("コピーに失敗しました: " + e);
  }
}

// -----------------------
// NEWS
// -----------------------
function renderNews(list, targetId) {
  const root = document.getElementById(targetId);
  if (!root) return;

  if (!Array.isArray(list) || !list.length) {
    root.innerHTML = '<div class="empty-hint">ニュースがありません。</div>';
    return;
  }

  const main = list[0];
  const others = list.slice(1);

  const mainThumb =
    main.thumbnail_url ||
    "https://kashite.space/wp-content/uploads/2023/10/kashite_open.jpg";

  let html = "";

  html += `
    <a class="news-main" href="${main.link}" target="_blank" rel="noopener">
      <div class="news-main-thumb" style="background-image:url('${mainThumb}')"></div>
      <div class="news-main-overlay">
        <div class="news-main-title">${main.title}</div>
        <div class="news-main-date">
          <span>⏱ ${formatNewsDate(main.date)}</span>
        </div>
      </div>
    </a>
  `;

  html += `<div class="news-side-list">`;

  others.forEach((item) => {
    const thumb = item.thumbnail_url || "";
    html += `
      <a class="news-item" href="${item.link}" target="_blank" rel="noopener">
        <div class="news-item-thumb" style="${
          thumb ? `background-image:url('${thumb}')` : ""
        }"></div>
        <div class="news-item-body">
          <div class="news-item-title">${item.title}</div>
          <div class="news-item-date">⏱ ${formatNewsDate(item.date)}</div>
        </div>
      </a>
    `;
  });

  html += `</div>`;

  root.classList.remove("empty-hint");
  root.innerHTML = html;
}

function initKashiteCalendars(root = document) {
  const containers = root.querySelectorAll
    ? root.querySelectorAll('[data-kashite-calendar]')
    : [];
  if (!containers.length) return;

  const today = new Date();

  const formatISODate = (d) => {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  };

  const defaultStart = formatISODate(today);
  const end = new Date(today.getTime());
  end.setMonth(end.getMonth() + 3);
  const defaultEnd = formatISODate(end);

  containers.forEach((container) => {
    const calendarId = container.dataset.calendarId;
    if (!calendarId) return;

    const start = container.dataset.calendarStart || defaultStart;
    const endStr = container.dataset.calendarEnd || defaultEnd;

    const path = `/calendar/${encodeURIComponent(calendarId)}?start=${start}&end=${endStr}`;

    callApi(path).then((json) => {
      const result = json?.data?.result;
      if (!result) {
        container.textContent = '予約情報を取得できませんでした。';
        return;
      }
      renderBookingSection(container, result);
    }).catch((err) => {
      console.error('[kashite] calendar error', err);
      container.textContent = 'エラーが発生しました。';
    });
  });
}

// -----------------------
// イベント登録
// -----------------------
document.addEventListener("DOMContentLoaded", () => {
  const rb = document.getElementById("remoteBase");
  if (rb) rb.textContent = REMOTE_BASE;

  document.addEventListener("click", async (e) => {
    const btn = e.target.closest("button[data-api]");
    if (!btn) return;
  
    const path = btn.dataset.api || "/";
    const targetId = btn.dataset.resultTarget;
  
    // 既存のレスポンス表示ロジックに乗せる
    const json = await callApi(path, { showResponse: true });
  
    // newsだけ特別描画
    if (path === "/news" && targetId) {
      const list = json?.data?.result ?? [];
      renderNews(list, targetId);
    }
  });

  document
    .getElementById("btn-clear-conditions")
    .addEventListener("click", onClearConditions);

  document
    .getElementById("btn-generate-search-url")
    .addEventListener("click", onGenerateSearchUrl);

  document
    .getElementById("btn-search-results")
    .addEventListener("click", onSearchResults);

  document
    .getElementById("btn-calendar")
    .addEventListener("click", () => callCalendar());

  const copyBtn = document.getElementById("btn-copy-response");
  if (copyBtn) {
    copyBtn.addEventListener("click", copyResponseJson);
  }

  // モーダル閉じる系
  const pm = document.getElementById("productModal");
  const pmClose = document.getElementById("pmClose");
  if (pmClose) {
    pmClose.addEventListener("click", closeProductModal);
  }
  if (pm) {
    const backdrop = pm.querySelector(".product-modal-backdrop");
    if (backdrop) {
      backdrop.addEventListener("click", closeProductModal);
    }
  }
  document.addEventListener("keydown", (ev) => {
    if (ev.key === "Escape") {
      closeProductModal();
    }
  });

  function readCartBridgeInputs() {
    const calendar_id = Number(document.getElementById("cartCalendarIdInput")?.value || 0);
    const date = (document.getElementById("cartDateInput")?.value || "").trim();
    const start_hour = (document.getElementById("cartStartHourInput")?.value || "").trim();
    const end_hour = (document.getElementById("cartEndHourInput")?.value || "").trim();
  
    if (!calendar_id || !date || !start_hour || !end_hour) {
      alert("calendar_id / date / start_hour / end_hour を入力してください");
      return null;
    }
    return { calendar_id, date, start_hour, end_hour };
  }
  
  async function copyText(text) {
    if (navigator.clipboard?.writeText) return navigator.clipboard.writeText(text);
    const ta = document.createElement("textarea");
    ta.value = text;
    ta.style.position = "fixed";
    ta.style.top = "-9999px";
    document.body.appendChild(ta);
    ta.select();
    document.execCommand("copy");
    document.body.removeChild(ta);
  }
  
  document.getElementById("btn-cart-bridge")?.addEventListener("click", () => {
    const params = readCartBridgeInputs();
    if (!params) return;
  
    const url = buildCartBridgeUrl(params);
    logLine(`bridge(open): ${url}`);
  
    // WebViewなら同一遷移が安全
    //location.href = url;
  
    // 新タブにしたいならこっち
    window.open(url, "_blank", "noopener");
  });
  
  document.getElementById("btn-cart-bridge-copy")?.addEventListener("click", async () => {
    const params = readCartBridgeInputs();
    if (!params) return;
  
    const url = buildCartBridgeUrl(params);
    await copyText(url);
    logLine(`bridge(copied): ${url}`);
  });

  loadInitialData();

  // ここでカレンダー初期化
  initKashiteCalendars();
});
