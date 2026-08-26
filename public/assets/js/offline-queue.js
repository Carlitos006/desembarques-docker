(function (window) {
    'use strict';

    var indexedDB = window.indexedDB;
    var supportsIndexedDB = typeof indexedDB !== 'undefined' && indexedDB !== null;
    var serviceWorkerSupported = 'serviceWorker' in navigator;
    var supportsBackgroundSync = serviceWorkerSupported && 'SyncManager' in window;
    var DB_NAME = 'desembarques-offline';
    var DB_VERSION = 1;
    var STORE_NAME = 'queue';
    var BACKGROUND_SYNC_TAG = 'desembarques-offline-sync';
    var PROCESS_QUEUE_MESSAGE = 'DES_EMBARQUES_PROCESS_OFFLINE_QUEUE';
    var defaultEndpoint = null;
    var dbPromise = null;
    var processingPromise = null;
    var isProcessing = false;

    function dispatchEvent(name, detail) {
        try {
            var event;
            if (typeof window.CustomEvent === 'function') {
                event = new window.CustomEvent(name, { detail: detail });
            } else {
                event = document.createEvent('CustomEvent');
                event.initCustomEvent(name, false, false, detail);
            }
            window.dispatchEvent(event);
        } catch (error) {
            console.error('Offline queue event dispatch failed:', error);
        }
    }

    function getServiceWorkerRegistration() {
        if (!serviceWorkerSupported) {
            return Promise.resolve(null);
        }

        return navigator.serviceWorker.ready.catch(function () {
            return null;
        });
    }

    function postMessageToServiceWorker(message, registration) {
        if (!serviceWorkerSupported || !message) {
            return Promise.resolve(false);
        }

        function send(reg) {
            if (!reg) {
                return false;
            }

            var worker = reg.active || reg.waiting || reg.installing;

            if (!worker || typeof worker.postMessage !== 'function') {
                return false;
            }

            try {
                worker.postMessage(message);
                return true;
            } catch (error) {
                console.error('Failed to post message to service worker:', error);
                return false;
            }
        }

        if (registration) {
            return Promise.resolve(send(registration));
        }

        return getServiceWorkerRegistration()
            .then(send)
            .catch(function () {
                return false;
            });
    }

    function scheduleBackgroundSync() {
        if (!serviceWorkerSupported) {
            return Promise.resolve(false);
        }

        return getServiceWorkerRegistration()
            .then(function (registration) {
                if (!registration) {
                    return false;
                }

                postMessageToServiceWorker({ type: PROCESS_QUEUE_MESSAGE }, registration);

                if (!supportsBackgroundSync || !registration.sync || typeof registration.sync.register !== 'function') {
                    return false;
                }

                return registration.sync
                    .register(BACKGROUND_SYNC_TAG)
                    .then(function () {
                        return true;
                    })
                    .catch(function (error) {
                        if (error && (error.name === 'InvalidStateError' || error.name === 'NotAllowedError')) {
                            return false;
                        }

                        console.error('Failed to register background sync:', error);
                        return false;
                    });
            })
            .catch(function () {
                return false;
            });
    }

    function openDatabase() {
        if (!supportsIndexedDB) {
            return Promise.reject(new Error('IndexedDB is not supported'));
        }

        if (dbPromise) {
            return dbPromise;
        }

        dbPromise = new Promise(function (resolve, reject) {
            var request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onupgradeneeded = function (event) {
                var database = event.target.result;
                if (!database.objectStoreNames.contains(STORE_NAME)) {
                    database.createObjectStore(STORE_NAME, { keyPath: 'id' });
                }
            };

            request.onsuccess = function (event) {
                resolve(event.target.result);
            };

            request.onerror = function (event) {
                reject(event.target.error || new Error('IndexedDB open error'));
            };
        });

        return dbPromise;
    }

    function withStore(mode, callback) {
        return openDatabase().then(function (database) {
            return new Promise(function (resolve, reject) {
                var transaction;
                try {
                    transaction = database.transaction(STORE_NAME, mode);
                } catch (transactionError) {
                    reject(transactionError);
                    return;
                }

                transaction.oncomplete = function () {
                    resolve();
                };

                transaction.onerror = function (event) {
                    reject(event.target.error || new Error('IndexedDB transaction error'));
                };

                try {
                    callback(transaction.objectStore(STORE_NAME), resolve, reject);
                } catch (callbackError) {
                    reject(callbackError);
                }
            });
        });
    }

    function readAllItems() {
        return withStore('readonly', function (store, resolve, reject) {
            var request = store.getAll();
            request.onsuccess = function (event) {
                resolve(event.target.result || []);
            };
            request.onerror = function (event) {
                reject(event.target.error || new Error('IndexedDB read error'));
            };
        });
    }

    function putItem(item) {
        return withStore('readwrite', function (store, resolve, reject) {
            var request = store.put(item);
            request.onsuccess = function () {
                resolve(item);
            };
            request.onerror = function (event) {
                reject(event.target.error || new Error('IndexedDB put error'));
            };
        });
    }

    function deleteItem(id) {
        return withStore('readwrite', function (store, resolve, reject) {
            var request = store.delete(id);
            request.onsuccess = function () {
                resolve();
            };
            request.onerror = function (event) {
                reject(event.target.error || new Error('IndexedDB delete error'));
            };
        });
    }

    function generateId() {
        return Date.now().toString(36) + '-' + Math.random().toString(16).slice(2);
    }

    function serializeFormData(formData) {
        var entries = [];
        if (!formData || typeof formData.forEach !== 'function') {
            return entries;
        }

        formData.forEach(function (value, key) {
            if (value instanceof File) {
                entries.push({
                    key: key,
                    type: 'file',
                    name: value.name || 'attachment',
                    fileType: value.type || '',
                    lastModified: typeof value.lastModified === 'number' ? value.lastModified : Date.now(),
                    blob: value
                });
            } else {
                entries.push({
                    key: key,
                    type: 'value',
                    value: value
                });
            }
        });

        return entries;
    }

    function toFileFromEntry(entry) {
        var blob = entry.blob;
        if (blob instanceof File) {
            return blob;
        }

        var options = { type: entry.fileType || '' };
        if (typeof entry.lastModified === 'number') {
            options.lastModified = entry.lastModified;
        }

        try {
            if (typeof File === 'function') {
                return new File([blob], entry.name || 'attachment', options);
            }
        } catch (error) {
            console.error('Failed to recreate File from Blob:', error);
        }

        if (blob instanceof Blob) {
            return blob;
        }

        return new Blob([], options);
    }

    function deserializeFormData(entries) {
        var formData = new FormData();

        (entries || []).forEach(function (entry) {
            if (!entry || !entry.key) {
                return;
            }

            if (entry.type === 'file') {
                var file = toFileFromEntry(entry);
                formData.append(entry.key, file, entry.name || file.name || 'attachment');
            } else {
                formData.append(entry.key, entry.value);
            }
        });

        return formData;
    }

    function enqueueFormData(formData, metadata) {
        if (!supportsIndexedDB) {
            return Promise.reject(new Error('Offline queue not supported'));
        }

        var entries = serializeFormData(formData);
        var item = {
            id: generateId(),
            entries: entries,
            metadata: metadata || {},
            createdAt: new Date().toISOString(),
            attempts: 0,
            lastAttemptAt: null
        };

        if (!item.metadata.endpoint) {
            item.metadata.endpoint = defaultEndpoint;
        }

        if (!item.metadata.endpoint) {
            return Promise.reject(new Error('Missing endpoint for offline queue item'));
        }

        return withStore('readwrite', function (store, resolve, reject) {
            var request = store.add(item);
            request.onsuccess = function () {
                dispatchEvent('offlineQueue:queued', { item: item });
                scheduleBackgroundSync();
                resolve(item);
            };
            request.onerror = function (event) {
                reject(event.target.error || new Error('IndexedDB add error'));
            };
        });
    }

    function fetchJson(endpoint, formData) {
        return fetch(endpoint, {
            method: 'POST',
            body: formData,
            credentials: 'include',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            var status = response.status;
            return response
                .json()
                .catch(function () {
                    return {};
                })
                .then(function (data) {
                    if (!response.ok) {
                        var error = new Error('Request failed with status ' + status);
                        error.status = status;
                        error.data = data;
                        throw error;
                    }

                    if (!data || data.success !== true) {
                        var validationError = new Error('Server validation error');
                        validationError.status = status;
                        validationError.data = data;
                        validationError.permanent = true;
                        throw validationError;
                    }

                    return data;
                });
        });
    }

    function processItem(item) {
        var endpoint = item && item.metadata ? item.metadata.endpoint : null;
        if (!endpoint) {
            var missingEndpointError = new Error('Missing endpoint for queued item');
            missingEndpointError.permanent = true;
            return Promise.reject(missingEndpointError);
        }

        var formData = deserializeFormData(item.entries);
        return fetchJson(endpoint, formData);
    }

    function markAttempt(item) {
        item.attempts = (item.attempts || 0) + 1;
        item.lastAttemptAt = new Date().toISOString();
        return putItem(item)
            .then(function () {
                scheduleBackgroundSync();
            })
            .catch(function (error) {
                console.error('Failed to update offline queue item:', error);
            });
    }

    function processQueueInternal() {
        if (!supportsIndexedDB || isProcessing) {
            return processingPromise || Promise.resolve({ sent: 0, failed: 0, remaining: 0, errors: [] });
        }

        isProcessing = true;
        processingPromise = readAllItems()
            .then(function (items) {
                var results = {
                    sent: 0,
                    failed: 0,
                    remaining: items.length,
                    errors: [],
                    sessionExpired: false
                };

                function processNext(index) {
                    if (index >= items.length) {
                        results.remaining = Math.max(items.length - results.sent - results.failed, 0);
                        return Promise.resolve(results);
                    }

                    var item = items[index];
                    if (!item) {
                        return processNext(index + 1);
                    }

                    if (!navigator.onLine) {
                        return Promise.resolve(results);
                    }

                    return processItem(item)
                        .then(function (data) {
                            results.sent += 1;
                            results.remaining = Math.max(results.remaining - 1, 0);
                            return deleteItem(item.id).then(function () {
                                dispatchEvent('offlineQueue:itemSynced', { item: item, response: data });
                                return processNext(index + 1);
                            });
                        })
                        .catch(function (error) {
                            var status = error && typeof error.status === 'number' ? error.status : null;
                            var errorDetail = {
                                item: item,
                                error: error,
                                status: status
                            };

                            if (status === 401) {
                                results.sessionExpired = true;
                                results.errors.push(errorDetail);
                                results.remaining = Math.max(results.remaining, items.length - index);
                                return Promise.resolve(results);
                            }

                            if (error && error.permanent === true) {
                                results.failed += 1;
                                results.remaining = Math.max(results.remaining - 1, 0);
                                results.errors.push(errorDetail);
                                return deleteItem(item.id)
                                    .catch(function (deleteError) {
                                        console.error('Failed to remove invalid offline item:', deleteError);
                                    })
                                    .then(function () {
                                        return processNext(index + 1);
                                    });
                            }

                            results.errors.push(errorDetail);
                            return markAttempt(item).then(function () {
                                return Promise.resolve(results);
                            });
                        });
                }

                return processNext(0);
            })
            .catch(function (error) {
                dispatchEvent('offlineQueue:error', { error: error });
                throw error;
            })
            .finally(function () {
                isProcessing = false;
            });

        function finalizeResults(results) {
            results = results || { sent: 0, failed: 0, remaining: 0, errors: [] };

            dispatchEvent('offlineQueue:sync', results);

            if (results.sessionExpired) {
                dispatchEvent('offlineQueue:sessionExpired', results);
            }

            if (results.sent > 0) {
                dispatchEvent('offlineQueue:syncSuccess', results);
            }

            if (results.errors && results.errors.length > 0 && !results.sessionExpired) {
                dispatchEvent('offlineQueue:syncError', results);
            }

            if (results.remaining > 0) {
                scheduleBackgroundSync();
            }

            return results;
        }

        return processingPromise
            .then(function (results) {
                return finalizeResults(results);
            })
            .catch(function (error) {
                if (error && typeof error === 'object' && 'remaining' in error) {
                    return finalizeResults(error);
                }

                throw error;
            });
    }

    function init(options) {
        options = options || {};
        if (options.endpoint) {
            defaultEndpoint = options.endpoint;
        }

        if (options.autoProcess !== false) {
            processQueueInternal().catch(function (error) {
                console.error('Failed to process offline queue on init:', error);
            });
        }
    }

    if (supportsIndexedDB) {
        window.addEventListener('online', function () {
            processQueueInternal().catch(function (error) {
                console.error('Failed to process offline queue after reconnect:', error);
            });
        });
    }

    window.DesembarquesOfflineQueue = {
        init: init,
        enqueueFormData: enqueueFormData,
        processQueue: processQueueInternal,
        isSupported: function () {
            return supportsIndexedDB;
        }
    };
}(window));
