// app.js (final, online-ready - proxy only for playlists / manifests)
const PROXY = "https://player.dimit.cc/proxy.php?url=";


const LAST_M3U_KEY = "iptv_last_m3u_v1";
const FAV_KEY = "iptv_favs_v1";

// Elements
const loadingEl = document.getElementById("loading");
const m3uUrlEl = document.getElementById("m3uUrl");
const loadBtn = document.getElementById("loadBtn");
const demoBtn = document.getElementById("demoBtn");
const searchEl = document.getElementById("search");
const listEl = document.getElementById("list");
const errEl = document.getElementById("error");
const videoEl = document.getElementById("video");
const nowPlayingEl = document.getElementById("nowPlaying");

const tabLiveEl = document.getElementById("tabLive");
const tabMoviesEl = document.getElementById("tabMovies");
const tabSeriesEl = document.getElementById("tabSeries");
const tabFavEl = document.getElementById("tabFav");
const groupSelectEl = document.getElementById("groupSelect");

// State
let allParsedItems = [];
let liveItems = [];
let movieItems = [];
let seriesItems = [];

let filteredItems = [];
let focusedIndex = 0;
let hls = null;
let currentPlayingId = null;

let currentType = "live"; // live | movies | series | fav
let currentGroup = "";
let renderLimit = 400;

let favSet = new Set(loadFavs());

// ---------- UI helpers ----------
function showError(msg) {
  if (!errEl) return;
  errEl.style.display = "block";
  errEl.textContent = msg;
}
function clearError() {
  if (!errEl) return;
  errEl.style.display = "none";
  errEl.textContent = "";
}
function setLoading(isLoading, text = "Loading…") {
  if (loadBtn) loadBtn.disabled = isLoading;
  if (demoBtn) demoBtn.disabled = isLoading;

  if (loadingEl) {
    loadingEl.style.display = isLoading ? "flex" : "none";
    const label = loadingEl.querySelector("span:last-child");
    if (label) label.textContent = text;
  }
}

// ---------- Last playlist ----------
function saveLastM3U(url) {
  try { localStorage.setItem(LAST_M3U_KEY, url || ""); } catch { }
}
function loadLastM3U() {
  try { return localStorage.getItem(LAST_M3U_KEY) || ""; } catch { return ""; }
}

// ---------- Favorites ----------
function loadFavs() {
  try {
    const raw = localStorage.getItem(FAV_KEY);
    return raw ? JSON.parse(raw) : [];
  } catch {
    return [];
  }
}
function saveFavs() {
  try { localStorage.setItem(FAV_KEY, JSON.stringify([...favSet])); } catch { }
}
function favId(item) {
  return item?.url || "";
}
function isFav(item) {
  return favSet.has(favId(item));
}
function toggleFav(item) {
  const id = favId(item);
  if (!id) return;

  if (favSet.has(id)) favSet.delete(id);
  else favSet.add(id);

  saveFavs();
  filterAndRender();
}

// ---------- Tabs / Groups ----------
function setActiveTab(type) {
  currentType = type;
  if (listEl) listEl.classList.remove("grid");

  if (tabLiveEl) tabLiveEl.classList.toggle("active", type === "live");
  if (tabMoviesEl) tabMoviesEl.classList.toggle("active", type === "movies");
  if (tabSeriesEl) tabSeriesEl.classList.toggle("active", type === "series");
  if (tabFavEl) tabFavEl.classList.toggle("active", type === "fav");

  currentGroup = "";
  if (groupSelectEl) groupSelectEl.value = "";
  renderLimit = 400;
  focusedIndex = 0;

  fillGroupDropdown(getBaseItemsByType());
  filterAndRender();
}

function fillGroupDropdown(items) {
  if (!groupSelectEl) return;

  const groups = new Set();
  for (const it of items) if (it.group) groups.add(it.group);

  const sorted = [...groups].sort((a, b) => a.localeCompare(b));
  groupSelectEl.innerHTML =
    `<option value="">All groups</option>` +
    sorted.map(g => `<option value="${escapeAttr(g)}">${escapeHtml(g)}</option>`).join("");
}

// ---------- M3U parser ----------
function parseM3U(text) {
  const lines = String(text)
    .split(/\r?\n/)
    .map(l => l.trim())
    .filter(Boolean);

  const items = [];
  let current = null;

  for (const line of lines) {
    if (line.startsWith("#EXTINF:")) {
      current = { name: "", group: "", logo: "", url: "" };

      const groupMatch = line.match(/group-title="([^"]*)"/i);
      const logoMatch = line.match(/tvg-logo="([^"]*)"/i);

      if (groupMatch) current.group = groupMatch[1];
      if (logoMatch) current.logo = logoMatch[1];

      const commaIndex = line.indexOf(",");
      if (commaIndex !== -1) current.name = line.slice(commaIndex + 1).trim();
    } else if (!line.startsWith("#") && current) {
      current.url = line;
      items.push(current);
      current = null;
    }
  }

  return items;
}

// ---------- Filtering ----------
function getBaseItemsByType() {
  if (currentType === "live") return liveItems;
  if (currentType === "movies") return movieItems;
  if (currentType === "series") return seriesItems;
  if (currentType === "fav") {
    const all = [...liveItems, ...movieItems, ...seriesItems];
    return all.filter(isFav);
  }
  return liveItems;
}

function getViewItems() {
  let items = getBaseItemsByType();
  if (currentGroup) items = items.filter(it => (it.group || "") === currentGroup);

  const q = (searchEl?.value || "").toLowerCase().trim();
  if (q) {
    items = items.filter(it =>
      (it.name || "").toLowerCase().includes(q) ||
      (it.group || "").toLowerCase().includes(q)
    );
  }
  return items;
}

function filterAndRender() {
  filteredItems = getViewItems();
  renderList(filteredItems);
}

// ---------- Render list ----------
function renderList(items) {
  if (!listEl) return;
  listEl.innerHTML = "";

  const view = items.slice(0, renderLimit);

  view.forEach((it, idx) => {
    const div = document.createElement("div");
    div.className = "item";
    if (favId(it) === currentPlayingId) div.classList.add("playing");

    div.tabIndex = 0;
    div.dataset.index = String(idx);

    const starred = isFav(it);
    const logoHtml = it.logo
      ? `<img class="logo" src="${escapeAttr(it.logo)}" alt="" loading="lazy" referrerpolicy="no-referrer"
           onerror="this.style.display='none'">`
      : "";

    div.innerHTML = `
      <div style="display:flex; gap:10px; align-items:center;">
        ${logoHtml}
        <div style="min-width:0; width:100%;">
          <div class="titleRow">
            <button class="favBtn" type="button" title="Favorite">${starred ? "★" : "☆"}</button>
            <div class="titleText">${escapeHtml(it.name || "Unnamed")}</div>
          </div>
          <div class="meta">
            ${it.group ? `<span>📁 ${escapeHtml(it.group)}</span>` : ""}
          </div>
        </div>
      </div>
    `;

    const favBtn = div.querySelector(".favBtn");
    if (favBtn) {
      favBtn.addEventListener("click", (ev) => {
        ev.stopPropagation();
        toggleFav(it);
      });
    }

    div.addEventListener("click", () => {
      focusedIndex = idx;
      focusItem();
      playItem(view[idx]);
    });

    listEl.appendChild(div);
  });

  if (items.length > view.length) {
    const wrap = document.createElement("div");
    wrap.style.padding = "8px";

    const btn = document.createElement("button");
    btn.type = "button";
    btn.textContent = `Load more (+400) — showing ${view.length} / ${items.length}`;
    btn.addEventListener("click", () => {
      renderLimit += 400;
      renderList(filteredItems);
    });

    wrap.appendChild(btn);
    listEl.appendChild(wrap);
  }

  focusedIndex = Math.min(focusedIndex, Math.max(0, view.length - 1));
  focusItem();
}

function focusItem() {
  if (!listEl) return;
  const nodes = [...listEl.querySelectorAll(".item")];
  nodes.forEach(n => n.blur());

  const node = nodes[focusedIndex];
  if (node) {
    node.focus({ preventScroll: false });
    node.scrollIntoView({ block: "nearest" });
  }
}

// ---------- Playback ----------
function stopPlayback() {
  if (nowPlayingEl) nowPlayingEl.textContent = "Stopped.";
  if (hls) { hls.destroy(); hls = null; }
  if (!videoEl) return;
  videoEl.pause();
  videoEl.removeAttribute("src");
  videoEl.load();
}

// IMPORTANT: proxy only for playlist & manifest, NOT for video segments
function proxifyIfNeeded(url) {
  // Proxy only for text playlists/manifests (CORS), not for media segments
  if (/\.(m3u8|m3u)(\?|$)/i.test(url)) {
    return PROXY + encodeURIComponent(url);
  }
  return url;
}

function playItem(item) {
  clearError();
  if (!item) return;

  currentPlayingId = favId(item);
  filterAndRender();

  if (nowPlayingEl) nowPlayingEl.textContent = `Playing: ${item.name || item.url}`;

  if (hls) { hls.destroy(); hls = null; }

  const url = item.url; // ✅ DIRECT, kein Proxy

  if (url.toLowerCase().includes(".m3u8")) {
    if (window.Hls && Hls.isSupported()) {
      hls = new Hls({ enableWorker: true, lowLatencyMode: true });
      hls.loadSource(url);
      hls.attachMedia(videoEl);
      hls.on(Hls.Events.ERROR, (_, data) => {
        showError(`HLS error: ${data.type} / ${data.details}\n${data.reason || ""}`.trim());
      });
      videoEl.play().catch(() => { });
      return;
    }
  }

  videoEl.src = url;
  videoEl.play().catch(err => showError(`Playback failed.\n${String(err)}`));
}



// ---------- Utils ----------
function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
  }[c]));
}
function escapeAttr(s) {
  return String(s).replace(/"/g, "&quot;");
}

// ---------- Load M3U ----------
async function loadM3U(url) {
  clearError();
  if (!url) return showError("Bitte M3U URL eingeben.");

  saveLastM3U(url);
  setLoading(true, "Loading playlist…");

  try {
    // Playlist always via proxy (CORS)
    const finalUrl = PROXY + encodeURIComponent(url);
    const res = await fetch(finalUrl, { cache: "no-store" });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);

    const text = await res.text();
    allParsedItems = parseM3U(text);

    liveItems = allParsedItems.filter(it => it.url.includes("/live/"));
    movieItems = allParsedItems.filter(it => it.url.includes("/movie/"));
    seriesItems = allParsedItems.filter(it => it.url.includes("/series/"));

    console.log("Parsed:", {
      total: allParsedItems.length,
      live: liveItems.length,
      movies: movieItems.length,
      series: seriesItems.length
    });

    setActiveTab("live");
  } catch (e) {
    showError(`Konnte M3U nicht laden.\nFehler: ${String(e)}`);
  } finally {
    setLoading(false);
  }
}

// ---------- Demo ----------
function loadDemo() {
  const demo = `#EXTM3U
#EXTINF:-1 tvg-logo="https://upload.wikimedia.org/wikipedia/commons/7/7a/Big_Buck_Bunny_logo.png" group-title="Demo",Big Buck Bunny (MP4)
https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4
#EXTINF:-1 tvg-logo="" group-title="Demo",Sintel (MP4)
https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/Sintel.mp4
`;
  allParsedItems = parseM3U(demo);
  liveItems = allParsedItems;
  movieItems = [];
  seriesItems = [];
  setActiveTab("live");
}

// ---------- Events ----------
if (loadBtn) loadBtn.addEventListener("click", () => loadM3U(m3uUrlEl.value.trim()));
if (demoBtn) demoBtn.addEventListener("click", loadDemo);

if (searchEl) {
  searchEl.addEventListener("input", () => {
    renderLimit = 400;
    focusedIndex = 0;
    filterAndRender();
  });
}

if (groupSelectEl) {
  groupSelectEl.addEventListener("change", () => {
    currentGroup = groupSelectEl.value || "";
    renderLimit = 400;
    focusedIndex = 0;
    filterAndRender();
  });
}

if (tabLiveEl) tabLiveEl.addEventListener("click", () => setActiveTab("live"));
if (tabMoviesEl) tabMoviesEl.addEventListener("click", () => setActiveTab("movies"));
if (tabSeriesEl) tabSeriesEl.addEventListener("click", () => setActiveTab("series"));
if (tabFavEl) tabFavEl.addEventListener("click", () => setActiveTab("fav"));

// Keyboard navigation — NOT while typing
document.addEventListener("keydown", (e) => {
  const tag = e.target?.tagName;
  const isTyping = tag === "INPUT" || tag === "TEXTAREA" || e.target?.isContentEditable;
  if (isTyping) return;

  const viewLen = Math.min(renderLimit, filteredItems.length);
  if (!viewLen) return;

  if (e.key === "ArrowDown") {
    e.preventDefault();
    focusedIndex = Math.min(focusedIndex + 1, viewLen - 1);
    focusItem();
  } else if (e.key === "ArrowUp") {
    e.preventDefault();
    focusedIndex = Math.max(focusedIndex - 1, 0);
    focusItem();
  } else if (e.key === "Enter") {
    e.preventDefault();
    const item = filteredItems.slice(0, renderLimit)[focusedIndex];
    if (item) playItem(item);
  } else if (e.key === "Backspace") {
    e.preventDefault();
    stopPlayback();
  } else if (e.key === "s" || e.key === "S") {
    e.preventDefault();
    const item = filteredItems.slice(0, renderLimit)[focusedIndex];
    if (item) toggleFav(item);
  } else if (e.key === "f" || e.key === "F") {
    e.preventDefault();
    if (videoEl?.requestFullscreen) videoEl.requestFullscreen();
  }
});

// ---------- Start ----------
const last = loadLastM3U();
if (m3uUrlEl && last) m3uUrlEl.value = last;

if (last) loadM3U(last);
else loadDemo();
