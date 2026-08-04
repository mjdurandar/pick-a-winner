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
    items: load(),          // [{ key, url, payload, meta, status, attempts, lastError, server_id, queuedAt }]
    isSyncing: false,
    isOnline: typeof navigator !== 'undefined' ? navigator.onLine : true,
    consecutiveFailures: 0, // Network-level failures in a row (reset by any server reply)
    lastSyncedAt: null,     // Timestamp of the last item that reached the server
    nextRetryAt: null,      // Backoff gate — don't retry before this timestamp
    clock: Date.now(),      // Ticks while items are pending so "x ago" text stays live
});

// ✅ Weak-signal thresholds.
const WEAK_FAILURE_THRESHOLD = 2;   // Network failures in a row before we call it weak
const WEAK_STALL_MS = 60000;        // A winner stuck unsent this long is a weak signal
const BACKOFF_STEPS = [15000, 30000, 60000]; // Retry delay after 1st, 2nd, 3rd+ failure

// ✅ Connection quality: 'online' | 'weak' | 'offline'
// navigator.onLine only reports "a network interface is attached" — it stays
// true on one bar of 4G, on a captive portal, and on wifi with no throughput.
// So a weak signal is inferred from real outcomes instead: repeated
// network-level failures, or winners sitting unsent for too long. This matters
// because a host on a weak signal currently sees the same "syncing…" state as
// a host whose winners are landing fine.
export function connectionQuality() {
    if (!syncState.isOnline) return 'offline';
    if (syncState.consecutiveFailures >= WEAK_FAILURE_THRESHOLD) return 'weak';

    const now = syncState.clock; // Read the tick so callers re-evaluate over time
    const stalled = syncState.items.some(item =>
        item.status === 'pending' &&
        item.queuedAt &&
        (now - item.queuedAt) > WEAK_STALL_MS
    );
    if (stalled && syncState.consecutiveFailures >= 1) return 'weak';

    return 'online';
}

// ✅ Highest attempt count among items still waiting to be sent.
export function maxPendingAttempts() {
    return syncState.items.reduce(
        (max, item) => (item.status === 'pending' ? Math.max(max, item.attempts) : max),
        0
    );
}

function formatAgo(ms) {
    const secs = Math.max(0, Math.round(ms / 1000));
    if (secs < 60) return `${secs}s`;
    const mins = Math.floor(secs / 60);
    return `${mins}m ${secs % 60}s`;
}

// ✅ Short "( 12 attempts, last sync 2m 5s ago )" detail for the weak-signal
// banner, so a host can tell a brief hiccup from a sync that is truly stuck.
export function pendingSyncDetail() {
    const parts = [];
    const attempts = maxPendingAttempts();
    if (attempts > 0) parts.push(`${attempts} attempt${attempts === 1 ? '' : 's'}`);
    if (syncState.lastSyncedAt) {
        parts.push(`last sync ${formatAgo(syncState.clock - syncState.lastSyncedAt)} ago`);
    } else {
        const oldest = syncState.items
            .filter(i => i.status === 'pending' && i.queuedAt)
            .reduce((min, i) => Math.min(min, i.queuedAt), Infinity);
        if (oldest !== Infinity) parts.push(`waiting ${formatAgo(syncState.clock - oldest)}`);
    }
    return parts.length ? ` (${parts.join(', ')})` : '';
}

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
            queuedAt: Date.now(), // Used to detect a weak signal (unsent for too long)
        });
    }
    persist();
    processQueue({ force: true }); // A fresh winner always tries immediately
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
// `force` skips the weak-signal backoff gate (used when the host just picked a
// winner, or when the device reconnects / the tab regains focus).
export async function processQueue({ force = false } = {}) {
    if (syncState.isSyncing) return;
    if (typeof navigator !== 'undefined' && !navigator.onLine) return;
    if (!syncState.items.some(i => i.status === 'pending')) return;
    // Backoff: on a weak signal each attempt can burn 20s of a thin pipe, so
    // space the retries out instead of hammering every cycle.
    if (!force && syncState.nextRetryAt && Date.now() < syncState.nextRetryAt) return;

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
                // The pipe works — clear the weak-signal state.
                syncState.consecutiveFailures = 0;
                syncState.nextRetryAt = null;
                syncState.lastSyncedAt = Date.now();
                syncState.clock = syncState.lastSyncedAt;
                persist();
            } catch (err) {
                item.attempts += 1;
                item.lastError = err.response ? `HTTP ${err.response.status}` : 'network';
                persist();
                if (err.response) {
                    // The server answered, so the connection itself is fine —
                    // not a weak signal. Still space out the retry so a session
                    // expiry or a server error isn't hammered every cycle.
                    syncState.consecutiveFailures = 0;
                    syncState.nextRetryAt = Date.now() + BACKOFF_STEPS[0];
                } else {
                    // Timeout / no response — this is what a weak signal looks like.
                    syncState.consecutiveFailures += 1;
                    syncState.clock = Date.now();
                    const step = BACKOFF_STEPS[Math.min(syncState.consecutiveFailures - 1, BACKOFF_STEPS.length - 1)];
                    syncState.nextRetryAt = Date.now() + step;
                }
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
        syncState.consecutiveFailures = 0; // Re-test the link instead of staying "weak"
        syncState.nextRetryAt = null;
        processQueue({ force: true });
    });
    window.addEventListener('offline', () => {
        syncState.isOnline = false;
    });
    // Flush the moment the host tabs back / reopens the browser, instead of
    // waiting for the retry timer.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') processQueue({ force: true });
    });
    window.addEventListener('focus', () => processQueue({ force: true }));
    // ✅ Guard: warn if the host tries to close/leave with unsynced winners,
    // since the queue only syncs while a Pick-a-Winner page is open.
    window.addEventListener('beforeunload', (e) => {
        if (hasPendingSync()) {
            e.preventDefault();
            e.returnValue = ''; // Triggers the browser's native "leave site?" prompt.
            return '';
        }
    });
    // Tick every 5s; the nextRetryAt gate above applies the weak-signal backoff.
    setInterval(() => processQueue(), 5000);
    // Keep the "last sync x ago" text in the banner live while anything is stuck.
    setInterval(() => {
        if (hasPendingSync()) syncState.clock = Date.now();
    }, 1000);
    processQueue({ force: true });
}
