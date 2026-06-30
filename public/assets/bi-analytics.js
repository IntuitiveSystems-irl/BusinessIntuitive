/*!
 * bi-analytics.js — Business Intuitive first-party analytics beacon.
 *
 * Privacy-friendly, cookieless behavior tracking that posts to the shared
 * analytics hub (the realdscr-intelligence Node app). The hub dedupes visitors
 * server-side via a daily hash of IP + UA, so there is NOTHING stored on the
 * client — no cookies, no localStorage, no fingerprint.
 *
 * Usage — drop ONE tag near the end of <body>:
 *   <script defer src="/assets/bi-analytics.js"
 *           data-hub="https://intelligence.businessintuitive.tech/api/track"
 *           data-site="main"></script>
 *
 * Data attributes:
 *   data-hub   (required) absolute URL or same-origin path of the /track endpoint.
 *   data-site  (required) short id of the property: main | gov | intelligence | buildops
 *   data-spa   (optional) "1" to also fire pageviews on history navigation.
 *
 * Captured behavior (all preflight-free text/plain beacons):
 *   - pageview               on load (path, referrer)
 *   - scroll_depth           value = 25 | 50 | 75 | 100 (once each, per page)
 *   - engaged_time           value = active seconds on page (on hide/unload)
 *   - click_outbound         path  = destination host (links leaving the site)
 *   - click_cta              name  = data-track value, or mailto/tel/cal.com intent
 *
 * Manual events:  window.biTrack("name", optionalNumericValue, optionalMeta)
 */
(function () {
  "use strict";

  var script =
    document.currentScript ||
    (function () {
      var s = document.getElementsByTagName("script");
      return s[s.length - 1];
    })();

  var HUB = (script && script.getAttribute("data-hub")) || "/api/track";
  var SITE = (script && script.getAttribute("data-site")) || "unknown";
  var SPA = (script && script.getAttribute("data-spa")) === "1";

  // Respect Do Not Track — skip silently if the user opted out.
  if (navigator.doNotTrack === "1" || window.doNotTrack === "1") return;

  // ── core sender: preflight-free so it works cross-origin without CORS dances.
  function send(payload) {
    try {
      payload.site = SITE;
      payload.path = payload.path || location.pathname + location.search;
      if (payload.referrer === undefined) payload.referrer = document.referrer || "";
      var body = JSON.stringify(payload);
      // text/plain keeps this a "simple" CORS request (no preflight). The hub
      // parses it as JSON.
      var blob;
      try {
        blob = new Blob([body], { type: "text/plain" });
      } catch (e) {
        blob = body;
      }
      if (navigator.sendBeacon && navigator.sendBeacon(HUB, blob)) return;
      fetch(HUB, {
        method: "POST",
        headers: { "Content-Type": "text/plain" },
        body: body,
        keepalive: true,
        mode: "no-cors",
      });
    } catch (e) {
      /* never let analytics break the page */
    }
  }

  function pageview() {
    send({ type: "pageview" });
  }

  function event(name, value, meta) {
    if (!name) return;
    var p = { type: "event", name: String(name).slice(0, 64) };
    if (typeof value === "number" && isFinite(value)) p.value = value;
    if (meta && typeof meta === "object") p.meta = meta;
    send(p);
  }

  // Expose a tiny manual API.
  window.biTrack = event;

  // ── 1) Pageview ──────────────────────────────────────────────
  if (document.readyState === "complete" || document.readyState === "interactive") {
    pageview();
  } else {
    document.addEventListener("DOMContentLoaded", pageview, { once: true });
  }

  // Optional SPA support: fire a pageview on pushState / popstate.
  if (SPA) {
    var fire = function () {
      setTimeout(pageview, 0);
    };
    ["pushState", "replaceState"].forEach(function (m) {
      var orig = history[m];
      if (typeof orig === "function") {
        history[m] = function () {
          var r = orig.apply(this, arguments);
          fire();
          return r;
        };
      }
    });
    window.addEventListener("popstate", fire);
  }

  // ── 2) Scroll depth ──────────────────────────────────────────
  var marks = [25, 50, 75, 100];
  var hit = {};
  function onScroll() {
    var doc = document.documentElement;
    var body = document.body;
    var scrollTop = window.pageYOffset || doc.scrollTop || body.scrollTop || 0;
    var winH = window.innerHeight || doc.clientHeight;
    var docH = Math.max(
      body.scrollHeight,
      doc.scrollHeight,
      body.offsetHeight,
      doc.offsetHeight,
      body.clientHeight,
      doc.clientHeight
    );
    var scrollable = docH - winH;
    var pct = scrollable <= 0 ? 100 : Math.min(100, Math.round(((scrollTop + winH) / docH) * 100));
    for (var i = 0; i < marks.length; i++) {
      var m = marks[i];
      if (pct >= m && !hit[m]) {
        hit[m] = true;
        event("scroll_depth", m);
      }
    }
    if (hit[100]) window.removeEventListener("scroll", throttledScroll);
  }
  var scrollTimer = null;
  function throttledScroll() {
    if (scrollTimer) return;
    scrollTimer = setTimeout(function () {
      scrollTimer = null;
      onScroll();
    }, 250);
  }
  window.addEventListener("scroll", throttledScroll, { passive: true });

  // ── 3) Engaged time (active seconds only) ────────────────────
  var engaged = 0;
  var lastTick = Date.now();
  var active = !document.hidden;
  var IDLE_MS = 30000; // pause counting after 30s of no interaction
  var lastInteract = Date.now();

  ["mousemove", "keydown", "scroll", "touchstart", "click"].forEach(function (ev) {
    window.addEventListener(
      ev,
      function () {
        lastInteract = Date.now();
        if (!active && !document.hidden) active = true;
      },
      { passive: true }
    );
  });

  setInterval(function () {
    var now = Date.now();
    var visible = !document.hidden;
    var notIdle = now - lastInteract < IDLE_MS;
    if (visible && notIdle) engaged += (now - lastTick) / 1000;
    lastTick = now;
  }, 1000);

  var engagedSent = false;
  function flushEngaged() {
    if (engagedSent) return;
    var secs = Math.round(engaged);
    if (secs > 0) {
      engagedSent = true;
      event("engaged_time", secs);
    }
  }
  document.addEventListener("visibilitychange", function () {
    if (document.hidden) flushEngaged();
    else {
      engagedSent = false; // allow another flush if they come back & leave again
      lastTick = Date.now();
    }
  });
  window.addEventListener("pagehide", flushEngaged);
  window.addEventListener("beforeunload", flushEngaged);

  // ── 4) Outbound + CTA clicks ─────────────────────────────────
  document.addEventListener(
    "click",
    function (e) {
      var el = e.target;
      while (el && el !== document.body && el.nodeName !== "A") el = el.parentNode;
      if (!el || el.nodeName !== "A") {
        // still allow non-anchor CTAs flagged with data-track
        var t = e.target;
        while (t && t !== document.body) {
          if (t.getAttribute && t.getAttribute("data-track")) {
            event("click_cta", undefined, { label: t.getAttribute("data-track").slice(0, 80) });
            return;
          }
          t = t.parentNode;
        }
        return;
      }
      var href = el.getAttribute("href") || "";
      if (!href || href.charAt(0) === "#") return;

      var dataTrack = el.getAttribute("data-track");
      var lower = href.toLowerCase();
      var intent = null;
      if (lower.indexOf("mailto:") === 0) intent = "email";
      else if (lower.indexOf("tel:") === 0) intent = "call";
      else if (lower.indexOf("cal.com") !== -1 || lower.indexOf("/book") !== -1) intent = "book";

      if (dataTrack || intent) {
        event("click_cta", undefined, {
          label: (dataTrack || intent).slice(0, 80),
          href: href.slice(0, 120),
        });
      }

      // Outbound: different host than current page.
      try {
        var url = new URL(href, location.href);
        if (url.host && url.host !== location.host && url.protocol.indexOf("http") === 0) {
          send({ type: "event", name: "click_outbound", path: url.host, referrer: location.pathname });
        }
      } catch (err) {
        /* relative or non-URL href */
      }
    },
    true
  );
})();
