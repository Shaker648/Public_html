/*
 * sw.js — First 1 Car service worker (phone notifications).
 *
 * Shows every push as a normal phone notification (lock screen, sound,
 * app icon) and opens the right page when it is tapped. It does no caching
 * on purpose, so the site always shows live data.
 *
 * iPhone rule: every push MUST show a notification, so one is always shown.
 */
const APP_NAME = 'First 1 Car';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
  let d = {};
  try { d = event.data ? event.data.json() : {}; }
  catch (e) { d = { title: APP_NAME, body: event.data ? event.data.text() : '' }; }

  const title = d.title || APP_NAME;
  const options = {
    body: d.body || '',
    icon: 'icons/icon-192.png?v=4',
    badge: 'icons/badge-96.png?v=4',
    tag: d.tag || undefined,
    renotify: !!d.tag,
    timestamp: d.ts || Date.now(),
    dir: d.dir || 'auto',
    lang: d.lang || 'ar',
    data: { url: d.url || 'dashboard.php' },
    vibrate: [120, 60, 120],
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL((event.notification.data && event.notification.data.url) || 'dashboard.php', self.registration.scope).href;
  event.waitUntil((async () => {
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const w of wins) {
      if (w.url.startsWith(self.registration.scope) && 'focus' in w) {
        try { if ('navigate' in w) await w.navigate(target); } catch (e) {}
        return w.focus();
      }
    }
    return self.clients.openWindow(target);
  })());
});

/* the browser renewed the subscription: register the new one right away */
self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil((async () => {
    try {
      const old = event.oldSubscription;
      const key = old && old.options ? old.options.applicationServerKey : null;
      const sub = event.newSubscription || (key ? await self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key }) : null);
      if (!sub) return;
      await fetch('push_subscribe.php', {
        method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'renew', old: old ? old.endpoint : '', sub: sub.toJSON() }),
      });
    } catch (e) {}
  })());
});
