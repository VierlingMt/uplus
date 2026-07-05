/* Unternehmen Plus – Service Worker (PWA).
 *
 * Strategie bewusst konservativ, da es sich um eine server-gerenderte App mit
 * Login/Session handelt:
 *   - Navigationen (HTML): NETZWERK zuerst  -> immer frische, korrekt
 *     authentifizierte Seiten; nur bei Offline eine schlanke Hinweisseite.
 *   - Statische Assets (css/js/img/fonts): CACHE zuerst, im Hintergrund
 *     aktualisieren (stale-while-revalidate).
 * Nur gleiche Origin wird angefasst (Google Fonts bleiben unberührt).
 */

'use strict';

var VERSION = 'uplus-v0.14.0';

// Relativ zum SW-Standort (= App-Scope), damit es im Web-Root wie im
// Unterordner (/uplus) funktioniert.
var PRECACHE = [
  './assets/css/app.css',
  './assets/js/app.js',
  './assets/img/logo.svg',
  './assets/img/icons/icon-192.png',
  './assets/img/icons/icon-512.png',
  './manifest.webmanifest'
];

self.addEventListener('install', function (event) {
  self.skipWaiting();
  event.waitUntil(
    caches.open(VERSION).then(function (cache) {
      // Einzeln hinzufügen, damit ein fehlendes Asset das Setup nicht abbricht.
      return Promise.all(PRECACHE.map(function (url) {
        return cache.add(new Request(url, { cache: 'reload' })).catch(function () { return null; });
      }));
    })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.map(function (key) {
        if (key !== VERSION) { return caches.delete(key); }
        return null;
      }));
    }).then(function () { return self.clients.claim(); })
  );
});

function offlineResponse() {
  var html =
    '<!doctype html><html lang="de"><head><meta charset="utf-8">' +
    '<meta name="viewport" content="width=device-width,initial-scale=1">' +
    '<title>Offline – Unternehmen Plus</title>' +
    '<style>body{font-family:system-ui,Segoe UI,sans-serif;background:#003594;color:#fff;' +
    'display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;text-align:center}' +
    '.box{max-width:460px;padding:34px}.box h1{margin:0 0 10px;font-size:22px}' +
    '.box p{color:#cbd8f0;line-height:1.5}button{margin-top:18px;background:#47D7AC;color:#04263f;' +
    'border:0;border-radius:9px;padding:11px 20px;font-weight:700;font-size:15px;cursor:pointer}</style>' +
    '</head><body><div class="box"><h1>Keine Verbindung</h1>' +
    '<p>Unternehmen Plus braucht eine Internetverbindung, um aktuelle Daten zu laden. ' +
    'Bitte prüfe deine Verbindung und versuche es erneut.</p>' +
    '<button onclick="location.reload()">Erneut versuchen</button></div></body></html>';
  return new Response(html, {
    status: 503,
    headers: { 'Content-Type': 'text/html; charset=utf-8' }
  });
}

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') { return; }

  var url;
  try { url = new URL(req.url); } catch (e) { return; }
  if (url.origin !== self.location.origin) { return; }

  // HTML-Navigationen: Netzwerk zuerst, offline -> Hinweisseite.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(function () {
        return caches.match(req).then(function (cached) {
          return cached || offlineResponse();
        });
      })
    );
    return;
  }

  // Statische Assets: stale-while-revalidate.
  if (/\.(?:css|js|svg|png|jpe?g|webp|gif|ico|woff2?)$/i.test(url.pathname)) {
    event.respondWith(
      caches.match(req).then(function (cached) {
        var network = fetch(req).then(function (res) {
          if (res && res.status === 200 && res.type === 'basic') {
            var copy = res.clone();
            caches.open(VERSION).then(function (cache) { cache.put(req, copy); });
          }
          return res;
        }).catch(function () { return cached; });
        return cached || network;
      })
    );
  }
});
