/*
 * push_client.js — turns phone notifications on/off for the signed-in user.
 * Used by notifications.php and notifications_admin.php.
 *
 *   F1Push.state()             → what this phone can do right now
 *   F1Push.enable(csrf, lang)  → ask permission, subscribe, save, send a test
 *   F1Push.disable(csrf)       → unsubscribe this phone
 *
 * iPhone/iPad: notifications only work in the app opened from the Home Screen
 * (iOS 16.4+). Android: work in Chrome / Edge / Samsung Internet directly.
 */
(function () {
  'use strict';
  const ua = navigator.userAgent || '';
  const isIOS = /iPhone|iPad|iPod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const isAndroid = /Android/i.test(ua);
  const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  const iosVersion = (() => { const m = ua.match(/OS (\d+)_(\d+)/i); return m ? parseFloat(m[1] + '.' + m[2]) : null; })();
  const inAppBrowser = /FBAN|FBAV|Instagram|Line\/|WhatsApp|Snapchat|TikTok/i.test(ua);
  const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

  const b64uToBytes = (s) => {
    const pad = '='.repeat((4 - (s.length % 4)) % 4);
    const raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
  };
  const post = (data) => fetch('push_subscribe.php', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data),
  }).then((r) => r.json());

  let regPromise = null;
  function registration() {
    if (!('serviceWorker' in navigator)) return Promise.reject(new Error('no_sw'));
    if (!regPromise) regPromise = navigator.serviceWorker.register('sw.js', { scope: './' }).then(() => navigator.serviceWorker.ready);
    return regPromise;
  }

  async function state() {
    const s = {
      supported, isIOS, isAndroid, standalone, iosVersion, inAppBrowser,
      secure: window.isSecureContext,
      permission: 'Notification' in window ? Notification.permission : 'unsupported',
      subscribed: false, endpoint: '', needsHomeScreen: false, tooOldIOS: false,
    };
    if (isIOS && iosVersion !== null && iosVersion < 16.4) s.tooOldIOS = true;
    if (isIOS && !standalone) s.needsHomeScreen = true;
    if (!supported || !s.secure) return s;
    try {
      const reg = await registration();
      const sub = await reg.pushManager.getSubscription();
      if (sub) {
        s.endpoint = sub.endpoint;
        const st = await post({ action: 'status', endpoint: sub.endpoint });
        s.subscribed = !!(st && st.known);
      }
    } catch (e) { s.error = String(e && e.message || e); }
    return s;
  }

  /* must be called from a tap (iPhone requires it) */
  async function enable(csrf, lang) {
    if (!supported) throw new Error('unsupported');
    if (!window.isSecureContext) throw new Error('insecure');
    const perm = await Notification.requestPermission();
    if (perm !== 'granted') throw new Error(perm === 'denied' ? 'denied' : 'dismissed');
    const reg = await registration();
    const k = await post({ action: 'key' });
    if (!k || !k.ok) throw new Error('server_key');
    const key = b64uToBytes(k.key);
    let sub = await reg.pushManager.getSubscription();
    // a subscription made with another key (e.g. old install) is replaced
    if (sub && sub.options && sub.options.applicationServerKey) {
      const cur = new Uint8Array(sub.options.applicationServerKey);
      if (cur.length !== key.length || cur.some((v, i) => v !== key[i])) { await sub.unsubscribe(); sub = null; }
    }
    if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
    const r = await post({ action: 'save', csrf, lang, test: 1, sub: sub.toJSON() });
    if (!r || !r.ok) throw new Error(r && r.error || 'save_failed');
    return r;
  }

  async function disable(csrf) {
    const reg = await registration();
    const sub = await reg.pushManager.getSubscription();
    if (sub) {
      await post({ action: 'remove', csrf, endpoint: sub.endpoint });
      await sub.unsubscribe();
    }
    return true;
  }

  function test(csrf, lang, id) { return post({ action: 'test', csrf, lang, id: id || 0 }); }

  window.F1Push = { state, enable, disable, test, registration, isIOS, isAndroid, standalone, supported };
  if (supported) registration().catch(() => {});
})();
