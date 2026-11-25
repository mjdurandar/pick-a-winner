import { reactive } from 'vue';

/**
 * Short messages for the Pick a Winner pages: "saved", "deleted", "nobody left
 * to draw". They sit at the top of the screen and go away by themselves, so a
 * host is never stopped by a pop-up in the middle of a draw.
 *
 *   toast.success('Jordan saved as a winner');
 *   toast.warning('Nobody left to draw', 'Everyone has already won.');
 */
export const toasts = reactive([]);

let nextId = 1;

export function dismissToast(id) {
    const i = toasts.findIndex((t) => t.id === id);
    if (i !== -1) toasts.splice(i, 1);
}

function push(type, title, text = '', ms) {
    const id = nextId++;
    toasts.push({ id, type, title, text });
    // A phone has room for a few at most; the oldest gives way.
    if (toasts.length > 3) toasts.shift();
    // Long enough to read: a one-liner goes quickly, anything with a second
    // line, or anything that went wrong, stays longer.
    const stay = ms ?? (type === 'success' && !text ? 3500 : 7000);
    setTimeout(() => dismissToast(id), stay);
    return id;
}

export const toast = {
    success: (title, text, ms) => push('success', title, text, ms),
    warning: (title, text, ms) => push('warning', title, text, ms),
    error: (title, text, ms) => push('error', title, text, ms),
};
