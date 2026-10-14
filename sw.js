// Pontodot — Service Worker v2
// UM service worker só pro app inteiro: Web Push próprio (VAPID) e
// Pusher Beams no mesmo arquivo. Antes eram dois registrados no mesmo
// escopo — o segundo apagava o primeiro e o push sumia.
importScripts('https://js.pusher.com/beams/service-worker.js');

self.addEventListener('install', e => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

self.addEventListener('push', e => {
  if (!e.data) return;
  let data;
  try { data = e.data.json(); } catch (err) { return; }
  // Mensagem do Beams: o script do Beams (acima) cuida dela.
  if (!data || !data.title || data.notification || data.xPusher || data.pusher) return;
  const options = {
    body: data.body || '',
    icon: data.icon || '/assets/icon-192.png',
    badge: '/assets/icon-192.png',
    data: { url: data.url || '/app.html' },
    vibrate: [200, 100, 200],
    requireInteraction: false,
    tag: data.tag || 'sd-notification',
    renotify: true
  };
  e.waitUntil(self.registration.showNotification(data.title || 'Pontodot', options));
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = e.notification.data?.url || '/app.html';
  e.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(lista => {
    for (const c of lista) {
      if (c.url.includes('sd.sergioduh.com') && 'focus' in c) { c.navigate && c.navigate(url); return c.focus(); }
    }
    if (clients.openWindow) return clients.openWindow(url);
  }));
});
