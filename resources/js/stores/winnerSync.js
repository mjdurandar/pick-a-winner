import { reactive } from 'vue';
import axios from 'axios';

// ✅ Offline-first sync queue for Pick-a-Winner saves.
// Winners are recorded here (and in localStorage) the instant they are
// confirmed, then synced to the server in the background. If internet is
// slow or down, the queue keeps retrying until every winner reaches the
// server — surviving page refreshes in between.

const STORAGE_KEY = 'paw_winner_sync_queue_v1';

function load() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        const parsed = raw ? JSON.parse(raw) : [];
        return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
        return [];
    }
}

function persist() {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(syncState.items));
    } catch (e) {
        console.error('Failed to persist winner sync queue:', e);
    }
}

export const syncState = reactive({
    items: load(),          // [{ key, url, payload, meta, status, attempts, lastError, server_id }]
    isSyncing: false,
    isOnline: typeof navigator !== 'undefined' ? navigator.onLine : true,
});

// ✅ Generate a client uuid (idempotency key for the server upsert)
export function makeUuid() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID();
    }
    return 'w-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
}

// ✅ Add (or merge into) a queued save. Items with the same key are merged so
// e.g. assigning a prize name to a still-unsynced winner updates the same
// pending request instead of creating a second one.
export function enqueue({ key, url, payload, meta }) {
    const existing = syncState.items.find(i => i.key === key);
    if (existing) {
        existing.url = url;
        existing.payload = { ...existing.payload, ...payload };
        existing.meta = { ...existing.meta, ...meta };
        existing.status = 'pending';
        existing.lastError = null;
    } else {
        syncState.items.push({
            key,
            url,
            payload,
            meta,
            status: 'pending', // 'pending' -> 'synced' (kept until server props confirm)
            attempts: 0,
            lastError: null,
            server_id: null,
        });
    }
    persist();
    processQueue();
}

export function removeItem(key) {
    const before = syncState.items.length;
    syncState.items = syncState.items.filter(i => i.key !== key);
    if (syncState.items.length !== before) persist();
}

// ✅ Drop 'synced' items once the server's prizes prop confirms they landed.
// (They are kept until then so the UI and eligibility checks never lose a
// winner between "synced" and the next props refresh.)
export function pruneSynced(serverPrizes) {
    if (!Array.isArray(serverPrizes)) return;
    const uuids = new Set(serverPrizes.map(p => p.client_uuid).filter(Boolean));
    const before = syncState.items.length;
    syncState.items = syncState.items.filter(item => {
        if (item.status !== 'synced') return true;
        if (item.meta?.type === 'create-winner') {
            return !uuids.has(item.meta.client_uuid);
        }
        if (item.meta?.type === 'assign-winner') {
            return !serverPrizes.some(p => p.id === item.meta.prize_id && p.winner_email === item.payload.winner_email);
        }
        if (item.meta?.type === 'delete-winner') {
            // Keep until the server props no longer contain the deleted prize
            return serverPrizes.some(p =>
                (item.meta.prize_id && p.id === item.meta.prize_id) ||
                (item.meta.client_uuid && p.client_uuid === item.meta.client_uuid)
            );
        }
        return false;
    });
    if (syncState.items.length !== before) persist();
}

// ✅ Try to push every pending item to the server, in order.
export async function processQueue() {
    if (syncState.isSyncing) return;
    if (typeof navigator !== 'undefined' && !navigator.onLine) return;
    if (!syncState.items.some(i => i.status === 'pending')) return;

    syncState.isSyncing = true;
    try {
        for (const item of syncState.items) {
            if (item.status !== 'pending') continue;
            try {
                const res = await axios.post(item.url, item.payload, {
                    headers: { Accept: 'application/json' },
                    timeout: 20000,
                });
                item.status = 'synced';
                item.server_id = res.data?.prize?.id ?? item.server_id;
                item.lastError = null;
                persist();
            } catch (err) {
                item.attempts += 1;
                item.lastError = err.response ? `HTTP ${err.response.status}` : 'network';
                persist();
                // Offline / session expired — stop and retry the whole queue later.
                if (!err.response || [401, 403, 419].includes(err.response.status)) {
                    break;
                }
            }
        }
    } finally {
        syncState.isSyncing = false;
    }
}

// ✅ True while any winner save/delete is still waiting to reach the server.
export function hasPendingSync() {
    return syncState.items.some(i => i.status === 'pending');
}

// ✅ Start background syncing: retry on reconnect, when the tab regains focus,
// and every 15 seconds. Also warns before leaving with unsynced winners.
let started = false;
export function startAutoSync() {
    if (started) return;
    started = true;
    window.addEventListener('online', () => {
        syncState.isOnline = true;
        processQueue();
    });
    window.addEventListener('offline', () => {
        syncState.isOnline = false;
    });
    // Flush the moment the host tabs back / reopens the browser, instead of
    // waiting up to 15s for the timer.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') processQueue();
    });
    window.addEventListener('focus', processQueue);
    // ✅ Guard: warn if the host tries to close/leave with unsynced winners,
    // since the queue only syncs while a Pick-a-Winner page is open.
    window.addEventListener('beforeunload', (e) => {
        if (hasPendingSync()) {
            e.preventDefault();
            e.returnValue = ''; // Triggers the browser's native "leave site?" prompt.
            return '';
        }
    });
    setInterval(processQueue, 15000);
    processQueue();
}
