// Keeps a copy of the page on the device, so it opens with no internet —
// the day the internet is down is exactly the day you need to note it.
// Answers from the copy at once, and refreshes the copy in the background,
// so a new version shows up the next time the page is opened.

const CACHE = 'onedost-dtr-v1';
const FILES = [
    './',
    'index.html',
    'css/app.css',
    'js/reader.js',
    'js/record.js',
    'js/app.js',
    'vendor/vue.global.prod.js',
    'vendor/bootstrap.min.css',
    'vendor/remixicon/remixicon.css',
    'vendor/remixicon/remixicon.woff2',
    'manifest.webmanifest',
    'icon.svg',
    'icon-192.png',
    'icon-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(FILES)));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))));
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET' || new URL(request.url).origin !== self.location.origin) return;

    event.respondWith(caches.open(CACHE).then(async (cache) => {
        const cached = await cache.match(request, { ignoreSearch: true });
        const fresh = fetch(request)
            .then((response) => {
                if (response.ok) cache.put(request, response.clone());
                return response;
            })
            .catch(() => cached || cache.match('index.html'));
        return cached || fresh;
    }));
});
