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
    data: { url: d.url || 'dashboard.php', acts: d.acts || {} },
    vibrate: [120, 60, 120],
  };
  // buttons right on the notification (Android / desktop; iPhone shows the notification only)
  if (Array.isArray(d.actions) && d.actions.length) options.actions = d.actions.slice(0, 2);
  const jobs = [self.registration.showNotification(title, options)];
  // the red number on the app icon = what this person hasn't seen yet
  if (typeof d.badge === 'number' && self.navigator && 'setAppBadge' in self.navigator) {
    jobs.push((d.badge > 0 ? self.navigator.setAppBadge(d.badge) : self.navigator.clearAppBadge()).catch(() => {}));
  }
  event.waitUntil(Promise.all(jobs));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const data = event.notification.data || {};
  const act = event.action && data.acts ? data.acts[event.action] : '';
  let target = new URL(data.url || 'dashboard.php', self.registration.scope).href;
  event.waitUntil((async () => {
    // «تمام» / «استلمت»: done in the background — nothing opens
    if (act && act.indexOf('notify_act.php') === 0) {
      try {
        const r = await fetch(new URL(act + '&bg=1', self.registration.scope).href, { credentials: 'include', cache: 'no-store' });
        const j = await r.json();
        if (j && j.ok) return;
      } catch (e) {}
      target = new URL(act, self.registration.scope).href;   // no answer: open it instead
    } else if (act) {
      target = new URL(act, self.registration.scope).href;
    }
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
// The page tells us this phone's current endpoint; kept so a renewal can be
// matched even when the browser doesn't give the old subscription (Safari).
const META = 'f1c-push-meta', EP_KEY = '/__f1c_endpoint';
async function keepEndpoint(ep) { try { const c = await caches.open(META); await c.put(EP_KEY, new Response(ep)); } catch (e) {} }
async function keptEndpoint() { try { const c = await caches.open(META); const r = await c.match(EP_KEY); return r ? await r.text() : ''; } catch (e) { return ''; } }
self.addEventListener('message', (event) => {
  const d = event.data || {};
  if (d.type === 'f1c-endpoint' && typeof d.endpoint === 'string') event.waitUntil(keepEndpoint(d.endpoint));
});

// The browser replaced this phone's subscription (happens now and then on
// Chrome and iPhone) — tell the server, which works even when nobody is
// logged in, so notifications keep coming.
self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil((async () => {
    try {
      const old = event.oldSubscription;
      let key = old && old.options ? old.options.applicationServerKey : null;
      if (!key) {   // Safari may not give the old one: fetch the server key again
        const k = await (await fetch('push_subscribe.php?action=key', { credentials: 'include' })).json().catch(() => null);
        if (k && k.key) { const pad = '='.repeat((4 - (k.key.length % 4)) % 4); key = Uint8Array.from(atob((k.key + pad).replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0)); }
      }
      const sub = event.newSubscription || (await self.registration.pushManager.getSubscription()) ||
                  (key ? await self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key }) : null);
      if (!sub) return;
      const hint = await keptEndpoint();
      const r = await fetch('push_subscribe.php', {
        method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'renew', old: old ? old.endpoint : '', hint, sub: sub.toJSON() }),
      });
      if (r.ok && (await r.json()).ok) await keepEndpoint(sub.endpoint);
    } catch (e) {}
  })());
});
