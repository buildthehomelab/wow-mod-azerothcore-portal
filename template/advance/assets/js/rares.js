/*
 * Rare map: draws mod-rare-tracker's live rare list on the game's own world maps.
 *
 * api/rares.php gives the rares (with zone map coordinates and raw world coordinates);
 * <maps>/maps.json gives the map images plus each continent's edges, so a rare can be placed on
 * a continent map from its world coordinates. Both views work without the images too.
 */
(function () {
  "use strict";

  var root = document.getElementById("rare-map");
  if (!root) return;

  var API = root.dataset.api;
  var MAPS = root.dataset.maps;
  var CONTINENTS = [
    { id: 0, name: "Eastern Kingdoms" },
    { id: 1, name: "Kalimdor" },
    { id: 530, name: "Outland" },
    { id: 571, name: "Northrend" }
  ];

  var el = {
    status: document.getElementById("rm-status"),
    tabs: document.getElementById("rm-tabs"),
    back: document.getElementById("rm-back"),
    title: document.getElementById("rm-title"),
    map: document.getElementById("rm-map"),
    art: document.getElementById("rm-art"),
    noart: document.getElementById("rm-noart"),
    pins: document.getElementById("rm-pins"),
    tip: document.getElementById("rm-tip"),
    search: document.getElementById("rm-search"),
    dead: document.getElementById("rm-dead"),
    zones: document.getElementById("rm-zones")
  };

  var state = {
    data: null,
    maps: null,
    offset: 0,        // server clock minus ours, in seconds
    error: "",
    continent: 0,
    zone: null,
    query: "",
    showDead: false,
    tipFor: null,     // spawn id the tooltip is showing
    pinned: false     // tooltip opened by a click rather than a hover
  };

  var pollTimer = null;

  // --- Storage and URL -----------------------------------------------------------------

  function remember(key, value) {
    try { localStorage.setItem("rare-map." + key, JSON.stringify(value)); } catch (e) { /* private mode */ }
  }

  function recall(key, fallback) {
    try {
      var value = localStorage.getItem("rare-map." + key);
      return value === null ? fallback : JSON.parse(value);
    } catch (e) {
      return fallback;
    }
  }

  function readHash() {
    var params = new URLSearchParams(location.hash.slice(1));
    var c = Number(params.get("c"));
    if (CONTINENTS.some(function (x) { return x.id === c; })) state.continent = c;
    state.zone = params.has("z") ? Number(params.get("z")) : null;
  }

  function writeHash() {
    var hash = "#c=" + state.continent + (state.zone !== null ? "&z=" + state.zone : "");
    if (location.hash !== hash) history.replaceState(null, "", hash);
  }

  // --- Helpers -------------------------------------------------------------------------

  function now() {
    return Date.now() / 1000 + state.offset;
  }

  function esc(text) {
    return String(text).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function duration(seconds) {
    seconds = Math.max(0, Math.round(seconds));
    if (seconds < 60) return seconds + "s";
    var m = Math.floor(seconds / 60);
    if (m < 60) return m + "m " + String(seconds % 60).padStart(2, "0") + "s";
    var h = Math.floor(m / 60);
    if (h < 24) return h + "h " + String(m % 60).padStart(2, "0") + "m";
    return Math.floor(h / 24) + "d " + (h % 24) + "h";
  }

  function zoneName(id) {
    return (state.data && state.data.zones[id]) || "Unknown zone";
  }

  function level(r) {
    if (r.level) return String(r.level);
    return r.minLevel === r.maxLevel ? String(r.minLevel) : r.minLevel + "-" + r.maxLevel;
  }

  // Where a rare sits on its continent's map: WorldMapTransforms first (the Burning Crusade
  // starting zones live on map 530 but are drawn on the old continents), then the continent's edges.
  function placeOnContinent(r) {
    var map = r.map, wx = r.wx, wy = r.wy;
    var transforms = (state.maps && state.maps.transforms) || [];
    for (var i = 0; i < transforms.length; i++) {
      var t = transforms[i];
      if (map === t.map && wx >= t.minX && wx <= t.maxX && wy >= t.minY && wy <= t.maxY) {
        map = t.newMap;
        wx += t.offsetX;
        wy += t.offsetY;
        break;
      }
    }

    var c = state.maps && state.maps.continents[map];
    if (!c) return { map: map, x: null, y: null };
    return { map: map, x: (wy - c.y1) / (c.y2 - c.y1) * 100, y: (wx - c.x1) / (c.x2 - c.x1) * 100 };
  }

  function continentOf(r) {
    var zone = state.maps && state.maps.zones[r.zone];
    return zone ? zone.continent : placeOnContinent(r).map;
  }

  function visible(r) {
    if (!state.showDead && r.state !== "up") return false;
    if (!state.query) return true;
    return r.name.toLowerCase().indexOf(state.query) !== -1 || zoneName(r.zone).toLowerCase().indexOf(state.query) !== -1;
  }

  function describeState(r) {
    if (r.state === "up") {
      if (!r.live) return "Up, at its spawn point";
      return (r.inCombat ? "In combat" : "Up") + ", " + r.hp + "% health";
    }
    if (r.respawnAt) {
      var left = r.respawnAt - now();
      return left > 0 ? "Dead, back in " + duration(left) : "Dead, respawning any moment";
    }
    return "Dead";
  }

  // --- Rendering -----------------------------------------------------------------------

  function renderStatus() {
    var data = state.data;
    el.status.classList.toggle("error", !!state.error);
    if (state.error && !data) {
      el.status.textContent = state.error;
      return;
    }
    if (!data || !data.generated) {
      el.status.textContent = "Loading…";
      return;
    }

    var age = now() - data.generated;
    var text = "<b>" + data.up + "</b> of " + data.rares.length + " rares up · ";
    text += age > (data.refresh || 30) * 2 ? "refreshing…" : "updated " + duration(age) + " ago";
    if (state.error) text += " · " + esc(state.error);
    el.status.innerHTML = text;
  }

  function renderTabs() {
    var counts = {};
    (state.data ? state.data.rares : []).forEach(function (r) {
      if (r.state !== "up") return;
      var c = continentOf(r);
      counts[c] = (counts[c] || 0) + 1;
    });

    el.tabs.innerHTML = CONTINENTS.map(function (c) {
      return '<button type="button" class="rm-tab" role="tab" data-continent="' + c.id + '" aria-selected="'
        + (c.id === state.continent) + '">' + esc(c.name)
        + (counts[c.id] ? '<span class="rm-count">' + counts[c.id] + "</span>" : "") + "</button>";
    }).join("");
  }

  function renderMap() {
    var continent = CONTINENTS.filter(function (c) { return c.id === state.continent; })[0];
    var inZone = state.zone !== null;
    var info = state.maps && (inZone ? state.maps.zones[state.zone] : state.maps.continents[state.continent]);

    el.title.textContent = inZone ? zoneName(state.zone) : continent.name;
    el.back.hidden = !inZone;
    el.back.querySelector("span").textContent = continent.name;

    var src = info ? MAPS + info.file : "";
    if (src) {
      if (el.art.getAttribute("src") !== src) el.art.src = src;
      el.art.alt = "Map of " + el.title.textContent;
    }
    el.art.hidden = !src;
    el.noart.hidden = !!src;
    el.noart.textContent = inZone
      ? "No map image for this zone yet. Rares are placed on a grid."
      : "No continent map images yet. Pick a zone from the list.";

    var rares = state.data ? state.data.rares : [];
    var pins = [];
    rares.forEach(function (r) {
      if (!visible(r)) return;
      var x, y;
      if (inZone) {
        if (r.zone !== state.zone || r.x === null) return;
        x = r.x; y = r.y;
      } else {
        var place = placeOnContinent(r);
        if (place.map !== state.continent || place.x === null) return;
        x = place.x; y = place.y;
      }
      if (x < 0 || x > 100 || y < 0 || y > 100) return;

      var cls = "rm-pin " + (r.state === "up" ? "up" : "dead") + (r.elite ? " elite" : "")
        + (r.state === "up" && r.live ? " live" : "") + (r.inCombat ? " combat" : "")
        + (r.spawn === state.tipFor ? " focus" : "");
      pins.push('<button type="button" class="' + cls + '" style="left:' + x.toFixed(2) + "%;top:" + y.toFixed(2)
        + '%" data-spawn="' + r.spawn + '" data-x="' + x + '" data-y="' + y + '" aria-label="'
        + esc(r.name + ", " + zoneName(r.zone) + ", " + describeState(r)) + '"></button>');
    });
    el.pins.innerHTML = pins.join("");

    if (state.tipFor !== null) {
      var pin = el.pins.querySelector('[data-spawn="' + state.tipFor + '"]');
      if (pin) showTip(pin); else hideTip();
    }
  }

  function renderList() {
    var rares = state.data ? state.data.rares : [];
    var zones = {};
    rares.forEach(function (r) {
      if (continentOf(r) !== state.continent) return;
      var z = zones[r.zone] || (zones[r.zone] = { id: r.zone, up: 0, total: 0, shown: [] });
      z.total++;
      if (r.state === "up") z.up++;
      if (visible(r)) z.shown.push(r);
    });

    var list = Object.keys(zones).map(function (k) { return zones[k]; })
      .filter(function (z) { return z.shown.length || (!state.query && z.id === state.zone); })
      .sort(function (a, b) { return zoneName(a.id).localeCompare(zoneName(b.id)); });

    if (!list.length) {
      el.zones.innerHTML = '<li class="rm-empty">' + (!state.data ? "Loading…"
        : state.query ? "No rares match “" + esc(state.query) + "”."
        : state.showDead ? "No rares on this continent." : "No rares are up on this continent right now.") + "</li>";
      return;
    }

    el.zones.innerHTML = list.map(function (z) {
      z.shown.sort(function (a, b) {
        return (a.state === "up" ? 0 : 1) - (b.state === "up" ? 0 : 1) || a.name.localeCompare(b.name);
      });
      return '<li class="rm-zone' + (z.id === state.zone ? " current" : "") + '">'
        + '<button type="button" data-zone="' + z.id + '">' + esc(zoneName(z.id))
        + "<span><b>" + z.up + "</b> / " + z.total + " up</span></button>"
        + '<ul class="rm-rares">' + z.shown.map(function (r) {
          var when = r.state === "up" ? (r.live ? r.hp + "%" : "")
            : r.respawnAt ? duration(r.respawnAt - now()) : "";
          return '<li><button type="button" class="rm-rare ' + (r.state === "up" ? "up" : "dead") + '" data-spawn="' + r.spawn + '">'
            + '<i class="rm-key ' + (r.state === "up" ? "up" : "dead") + (r.elite ? " elite" : "") + '"></i>'
            + '<span class="name">' + esc(r.name) + ' <span class="lvl">' + level(r) + (r.elite ? " elite" : "") + "</span></span>"
            + '<span class="when" data-respawn="' + (r.state === "up" ? "" : r.respawnAt || "") + '">' + when + "</span>"
            + "</button></li>";
        }).join("") + "</ul></li>";
    }).join("");
  }

  function render() {
    renderStatus();
    renderTabs();
    renderMap();
    renderList();
    writeHash();
  }

  // --- Tooltip -------------------------------------------------------------------------

  function findRare(spawn) {
    var rares = state.data ? state.data.rares : [];
    for (var i = 0; i < rares.length; i++) if (rares[i].spawn === spawn) return rares[i];
    return null;
  }

  function showTip(pin) {
    var r = findRare(Number(pin.dataset.spawn));
    if (!r) return hideTip();

    var x = Number(pin.dataset.x), y = Number(pin.dataset.y);
    var coords = r.x !== null ? r.x.toFixed(1) + ", " + r.y.toFixed(1) : "";
    el.tip.innerHTML = '<strong class="' + (r.state === "up" ? "" : "dead") + '">' + esc(r.name) + "</strong>"
      + '<div class="sub">Level ' + level(r) + (r.elite ? " rare elite" : " rare") + "</div>"
      + '<div class="sub">' + esc(zoneName(r.zone)) + (coords ? " (" + coords + ")" : "") + "</div>"
      + '<div class="state">' + esc(describeState(r)) + "</div>";
    el.tip.style.left = x + "%";
    el.tip.style.top = Math.min(Math.max(y, 12), 88) + "%";
    el.tip.classList.toggle("left", x > 62);
    el.tip.hidden = false;
    state.tipFor = r.spawn;

    Array.prototype.forEach.call(el.pins.querySelectorAll(".focus"), function (p) { p.classList.remove("focus"); });
    pin.classList.add("focus");
  }

  function hideTip() {
    el.tip.hidden = true;
    state.tipFor = null;
    state.pinned = false;
    Array.prototype.forEach.call(el.pins.querySelectorAll(".focus"), function (p) { p.classList.remove("focus"); });
  }

  // --- Data ----------------------------------------------------------------------------

  function poll() {
    clearTimeout(pollTimer);
    fetch(API, { cache: "no-store" })
      .then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (body) {
          if (!res.ok || !body.rares) throw new Error(body.error || "The rare list isn't available.");
          var serverNow = Number(res.headers.get("X-Server-Time")) || body.generated || Date.now() / 1000;
          state.offset = serverNow - Date.now() / 1000;
          return body;
        });
      })
      .then(function (data) {
        state.data = data;
        state.error = "";
        render();
        var refresh = data.refresh || 30;
        // An old list means the tracker was idle; a fresh one is built within a second.
        var stale = !data.generated || now() - data.generated > refresh * 2;
        pollTimer = setTimeout(poll, stale ? 2000 : refresh * 1000);
      })
      .catch(function (err) {
        state.error = err.message || "The rare list isn't available.";
        renderStatus();
        pollTimer = setTimeout(poll, 15000);
      });
  }

  function loadMaps() {
    return fetch(MAPS + "maps.json")
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (maps) { state.maps = maps; })
      .catch(function () { state.maps = null; });
  }

  // --- Events --------------------------------------------------------------------------

  el.tabs.addEventListener("click", function (e) {
    var tab = e.target.closest("[data-continent]");
    if (!tab) return;
    state.continent = Number(tab.dataset.continent);
    state.zone = null;
    hideTip();
    render();
  });

  el.back.addEventListener("click", function () {
    state.zone = null;
    hideTip();
    render();
  });

  el.zones.addEventListener("click", function (e) {
    var zoneButton = e.target.closest("[data-zone]");
    if (zoneButton) {
      var zone = Number(zoneButton.dataset.zone);
      state.zone = state.zone === zone ? null : zone;
      hideTip();
      render();
      return;
    }

    var rareButton = e.target.closest(".rm-rare");
    if (!rareButton) return;
    var r = findRare(Number(rareButton.dataset.spawn));
    if (!r) return;
    state.zone = r.zone;
    state.tipFor = r.spawn;
    state.pinned = true;
    render();
    var pin = el.pins.querySelector('[data-spawn="' + r.spawn + '"]');
    if (pin) {
      pin.classList.add("flash");
      if (window.matchMedia("(max-width: 960px)").matches) el.map.scrollIntoView({ behavior: "smooth", block: "center" });
    }
  });

  el.pins.addEventListener("mouseover", function (e) {
    var pin = e.target.closest(".rm-pin");
    if (pin && !state.pinned) showTip(pin);
  });

  el.pins.addEventListener("mouseout", function (e) {
    var pin = e.target.closest(".rm-pin");
    if (pin && !state.pinned && !pin.contains(e.relatedTarget)) hideTip();
  });

  el.pins.addEventListener("focusin", function (e) {
    var pin = e.target.closest(".rm-pin");
    if (pin) showTip(pin);
  });

  el.pins.addEventListener("click", function (e) {
    var pin = e.target.closest(".rm-pin");
    if (!pin) {
      hideTip();
      return;
    }
    var r = findRare(Number(pin.dataset.spawn));
    if (state.zone === null && r) {
      // On a continent, a click opens the rare's zone.
      state.zone = r.zone;
      state.tipFor = r.spawn;
      state.pinned = true;
      render();
      return;
    }
    state.pinned = true;
    showTip(pin);
  });

  el.map.addEventListener("click", function (e) {
    if (!e.target.closest(".rm-pin")) hideTip();
  });

  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") hideTip();
  });

  el.search.addEventListener("input", function () {
    state.query = el.search.value.trim().toLowerCase();
    renderMap();
    renderList();
  });

  el.dead.addEventListener("change", function () {
    state.showDead = el.dead.checked;
    remember("showDead", state.showDead);
    renderMap();
    renderList();
  });

  window.addEventListener("hashchange", function () {
    readHash();
    hideTip();
    render();
  });

  // Respawn countdowns and "updated … ago" tick every second without re-rendering everything.
  setInterval(function () {
    renderStatus();
    Array.prototype.forEach.call(el.zones.querySelectorAll("[data-respawn]"), function (span) {
      var at = Number(span.dataset.respawn);
      if (at) span.textContent = duration(at - now());
    });
    if (state.tipFor !== null) {
      var r = findRare(state.tipFor);
      var line = el.tip.querySelector(".state");
      if (r && line && r.state !== "up") line.textContent = describeState(r);
    }
  }, 1000);

  // --- Start ---------------------------------------------------------------------------

  state.showDead = recall("showDead", false);
  el.dead.checked = state.showDead;
  readHash();
  render();
  loadMaps().then(function () {
    render();
    poll();
  });
})();
