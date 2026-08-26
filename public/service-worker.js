const CACHE_VERSION = 'v43';
const STATIC_CACHE = `desembarques-static-${CACHE_VERSION}`;
const PRECACHE_URLS = [
  './assets/css/styles.css',
  './assets/css/navbar-premium.css',
  './assets/css/aviso-expediente.css',
  './assets/css/aviso-import.css',
  './assets/css/dashboard-avisos.css',
  './assets/js/theme.js',
  './assets/js/i18n-runtime.js?v=42',
  './assets/js/offline-queue.js',
  './assets/js/form.js',
  './assets/js/excel-first.js',
  './assets/js/aviso-pdf.js?v=42',
  './assets/js/aviso-expediente-documents.js',
  './assets/js/aviso-expediente-photos.js?v=42',
  './assets/js/aviso-expediente-status.js',
  './assets/js/aviso-expediente-merchandise.js?v=43',
  './assets/js/alcance-pdf.js?v=42',
  './assets/js/aviso-expediente-alcances.js?v=42',
  './assets/js/aviso-import.js?v=42',
  './assets/js/aviso-soft-delete.js?v=42',
  './assets/js/dashboard-avisos.js',
  './assets/js/dashboard-avisos-export.js',
  './assets/js/reports.js',
  './assets/pdf/aviso-desembarque-plantilla.pdf',
  './assets/img/icons/app-icon.svg'
];

const OFFLINE_DB_NAME = 'desembarques-offline';
const OFFLINE_DB_VERSION = 1;
const OFFLINE_STORE_NAME = 'queue';
const BACKGROUND_SYNC_TAG = 'desembarques-offline-sync';
const PROCESS_QUEUE_MESSAGE = 'DES_EMBARQUES_PROCESS_OFFLINE_QUEUE';
const SET_LANGUAGE_MESSAGE = 'SET_APP_LANGUAGE';
let APP_LANGUAGE = 'es';

function openOfflineQueueDatabase() {
  if (!self.indexedDB) {
    return Promise.reject(new Error('IndexedDB is not available in service worker.'));
  }

  return new Promise((resolve, reject) => {
    const request = self.indexedDB.open(OFFLINE_DB_NAME, OFFLINE_DB_VERSION);

    request.onupgradeneeded = (event) => {
      const database = event.target.result;
      if (!database.objectStoreNames.contains(OFFLINE_STORE_NAME)) {
        database.createObjectStore(OFFLINE_STORE_NAME, { keyPath: 'id' });
      }
    };

    request.onsuccess = (event) => {
      resolve(event.target.result);
    };

    request.onerror = (event) => {
      reject(event.target.error || new Error('Failed to open offline queue database.'));
    };
  });
}

function withOfflineQueueStore(mode, callback) {
  return openOfflineQueueDatabase().then(
    (database) =>
      new Promise((resolve, reject) => {
        let transaction;

        try {
          transaction = database.transaction(OFFLINE_STORE_NAME, mode);
        } catch (error) {
          reject(error);
          return;
        }

        transaction.oncomplete = () => resolve();
        transaction.onerror = (event) => {
          reject(event.target.error || new Error('Offline queue transaction failed.'));
        };

        try {
          callback(transaction.objectStore(OFFLINE_STORE_NAME), resolve, reject);
        } catch (error) {
          reject(error);
        }
      })
  );
}

function readAllOfflineQueueItems() {
  return withOfflineQueueStore('readonly', (store, resolve, reject) => {
    const request = store.getAll();
    request.onsuccess = (event) => {
      resolve(event.target.result || []);
    };
    request.onerror = (event) => {
      reject(event.target.error || new Error('Failed to read offline queue items.'));
    };
  });
}

function updateOfflineQueueItem(item) {
  return withOfflineQueueStore('readwrite', (store, resolve, reject) => {
    const request = store.put(item);
    request.onsuccess = () => resolve(item);
    request.onerror = (event) => {
      reject(event.target.error || new Error('Failed to update offline queue item.'));
    };
  });
}

function deleteOfflineQueueItem(id) {
  return withOfflineQueueStore('readwrite', (store, resolve, reject) => {
    const request = store.delete(id);
    request.onsuccess = () => resolve();
    request.onerror = (event) => {
      reject(event.target.error || new Error('Failed to delete offline queue item.'));
    };
  });
}

function toFileFromEntry(entry) {
  const blob = entry && entry.blob;

  if (blob instanceof File) {
    return blob;
  }

  const options = { type: entry && entry.fileType ? entry.fileType : '' };

  if (entry && typeof entry.lastModified === 'number') {
    options.lastModified = entry.lastModified;
  }

  try {
    if (typeof File === 'function' && blob) {
      return new File([blob], (entry && entry.name) || 'attachment', options);
    }
  } catch (error) {
    console.warn('Failed to recreate File from Blob in service worker:', error);
  }

  if (blob instanceof Blob) {
    return blob;
  }

  return new Blob([], options);
}

function deserializeFormData(entries) {
  const formData = new FormData();

  (entries || []).forEach((entry) => {
    if (!entry || !entry.key) {
      return;
    }

    if (entry.type === 'file') {
      const file = toFileFromEntry(entry);
      formData.append(entry.key, file, entry.name || (file && file.name) || 'attachment');
    } else {
      formData.append(entry.key, entry.value);
    }
  });

  return formData;
}

function fetchQueueItem(endpoint, formData) {
  return fetch(endpoint, {
    method: 'POST',
    body: formData,
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    }
  }).then((response) => {
    const status = response.status;

    return response
      .json()
      .catch(() => ({}))
      .then((data) => {
        if (!response.ok) {
          const error = new Error(`Request failed with status ${status}`);
          error.status = status;
          error.data = data;
          throw error;
        }

        if (!data || data.success !== true) {
          const validationError = new Error('Server validation error');
          validationError.status = status;
          validationError.data = data;
          validationError.permanent = true;
          throw validationError;
        }

        return data;
      });
  });
}

function markQueueItemAttempt(item) {
  if (!item) {
    return Promise.resolve();
  }

  item.attempts = (item.attempts || 0) + 1;
  item.lastAttemptAt = new Date().toISOString();

  return updateOfflineQueueItem(item).catch((error) => {
    console.error('Service worker failed to update offline queue attempt metadata:', error);
  });
}

function processOfflineQueueOnce() {
  return readAllOfflineQueueItems()
    .then((items) => {
      if (!items.length) {
        return { sent: 0, failed: 0, remaining: 0 };
      }

      const results = {
        sent: 0,
        failed: 0,
        remaining: items.length,
        errors: [],
        sessionExpired: false
      };

      let chain = Promise.resolve();

      items.forEach((item) => {
        chain = chain.then(() => {
          if (!item) {
            return undefined;
          }

          return Promise.resolve()
            .then(() => {
              if (!item.metadata || !item.metadata.endpoint) {
                const error = new Error('Missing endpoint for queued item');
                error.permanent = true;
                throw error;
              }

              const formData = deserializeFormData(item.entries);
              return fetchQueueItem(item.metadata.endpoint, formData);
            })
            .then((data) =>
              deleteOfflineQueueItem(item.id).then(() => {
                results.sent += 1;
                results.remaining = Math.max(results.remaining - 1, 0);
                return data;
              })
            )
            .catch((error) => {
              const status = error && typeof error.status === 'number' ? error.status : null;

              if (status === 401) {
                results.sessionExpired = true;
                results.errors.push({ item, error, status });
                results.remaining = Math.max(results.remaining, 1 + results.sent + results.failed);
                return Promise.reject(error);
              }

              if (error && error.permanent === true) {
                results.failed += 1;
                results.remaining = Math.max(results.remaining - 1, 0);
                results.errors.push({ item, error, status });
                return deleteOfflineQueueItem(item.id);
              }

              results.errors.push({ item, error, status });
              return markQueueItemAttempt(item).then(() => {
                throw error;
              });
            });
        });
      });

      return chain
        .then(() => {
          if (results.remaining > 0) {
            return Promise.reject(results);
          }

          return results;
        })
        .catch((error) => {
          if (error && typeof error === 'object' && 'remaining' in error) {
            return Promise.reject(error);
          }

          if (results.remaining > 0) {
            return Promise.reject(results);
          }

          return Promise.reject(error);
        });
    })
    .catch((error) => {
      if (error && typeof error === 'object' && 'remaining' in error) {
        console.warn('Service worker offline queue processing incomplete; remaining items:', error.remaining);
        throw error;
      }

      console.error('Service worker failed to process offline queue:', error);
      throw error;
    });
}

const STATIC_DESTINATIONS = new Set(['style', 'script', 'image', 'font']);
const APP_SCOPE_PATH = new URL(self.registration.scope).pathname.replace(/\/$/, '');

function getScopedPath(requestUrl) {
  let pathname = requestUrl.pathname;

  if (APP_SCOPE_PATH && pathname.startsWith(APP_SCOPE_PATH)) {
    pathname = pathname.slice(APP_SCOPE_PATH.length);
  }

  if (!pathname.startsWith('/')) {
    pathname = `/${pathname}`;
  }

  return pathname;
}

function isDynamicRequest(request, requestUrl) {
  const scopedPath = getScopedPath(requestUrl);

  return (
    request.mode === 'navigate' ||
    request.destination === 'document' ||
    scopedPath === '/' ||
    scopedPath.endsWith('.php') ||
    scopedPath.startsWith('/api/') ||
    scopedPath.startsWith('/table/')
  );
}

function isEventStreamRequest(requestUrl) {
  return getScopedPath(requestUrl) === '/api/desembarques/observaciones_stream.php';
}

function isStaticRequest(request, requestUrl) {
  const scopedPath = getScopedPath(requestUrl);

  return (
    STATIC_DESTINATIONS.has(request.destination) ||
    scopedPath.startsWith('/assets/') ||
    scopedPath.endsWith('.webmanifest')
  );
}

function isCacheableStaticResponse(response) {
  if (!response || response.status !== 200 || response.type !== 'basic') {
    return false;
  }

  const cacheControl = response.headers.get('Cache-Control') || '';
  return !/(?:no-store|private)/i.test(cacheControl);
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches
      .open(STATIC_CACHE)
      .then((cache) => cache.addAll(PRECACHE_URLS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((cacheNames) =>
        Promise.all(
          cacheNames
            .filter(
              (cacheName) =>
                cacheName.startsWith('desembarques-static-') &&
                cacheName !== STATIC_CACHE
            )
            .map((cacheName) => caches.delete(cacheName))
        )
      )
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;

  if (request.method !== 'GET') {
    return;
  }

  const requestUrl = new URL(request.url);

  if (requestUrl.origin !== self.location.origin) {
    return;
  }

  // EventSource debe conectarse directamente a la red para conservar el flujo SSE.
  if (isEventStreamRequest(requestUrl)) {
    return;
  }

  // Nunca guardar páginas PHP, navegaciones, APIs ni las herramientas de tabla.
  // Estas respuestas contienen sesiones o datos de base de datos y deben ser frescas.
  if (isDynamicRequest(request, requestUrl)) {
    event.respondWith(
      fetch(request, { cache: 'no-store' }).catch(() =>
        new Response(APP_LANGUAGE === 'en'
          ? 'You are offline. Try again when your network connection is restored.'
          : 'Sin conexión. Vuelve a intentarlo cuando recuperes la red.', {
          status: 503,
          headers: {
            'Content-Type': 'text/plain; charset=UTF-8',
            'Cache-Control': 'no-store'
          }
        })
      )
    );
    return;
  }

  if (!isStaticRequest(request, requestUrl)) {
    return;
  }

  // Solo los recursos estáticos usan caché. Se entrega el recurso guardado y se
  // actualiza en segundo plano para conservar rapidez sin congelar datos dinámicos.
  event.respondWith(
    caches.match(request).then((cachedResponse) => {
      const networkRequest = fetch(request, { cache: 'no-cache' })
        .then((networkResponse) => {
          if (isCacheableStaticResponse(networkResponse)) {
            const clonedResponse = networkResponse.clone();
            caches.open(STATIC_CACHE).then((cache) => cache.put(request, clonedResponse));
          }

          return networkResponse;
        });

      if (cachedResponse) {
        event.waitUntil(networkRequest.catch(() => undefined));
        return cachedResponse;
      }

      return networkRequest;
    })
  );
});

self.addEventListener('message', (event) => {
  const data = event && event.data;

  if (!data) {
    return;
  }

  if (data.type === SET_LANGUAGE_MESSAGE) {
    APP_LANGUAGE = String(data.language || '').toLowerCase().startsWith('en') ? 'en' : 'es';
    return;
  }

  if (data.type !== PROCESS_QUEUE_MESSAGE) {
    return;
  }

  event.waitUntil(
    processOfflineQueueOnce().catch((error) => {
      if (error && typeof error === 'object' && 'remaining' in error) {
        if (error.remaining > 0) {
          console.warn('Service worker deferred offline sync; pending items remain:', error.remaining);
        }
        return undefined;
      }

      console.error('Service worker message-triggered sync failed:', error);
      return undefined;
    })
  );
});

self.addEventListener('sync', (event) => {
  if (event.tag !== BACKGROUND_SYNC_TAG) {
    return;
  }

  event.waitUntil(processOfflineQueueOnce());
});
