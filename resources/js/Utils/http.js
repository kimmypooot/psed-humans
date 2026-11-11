// Plain fetch for the pages that keep their own state (uploads, live progress,
// the review) instead of reloading. Laravel's XSRF cookie is sent back as the header it checks.
const xsrfToken = () => {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
};

/**
 * Sends a request and answers { ok, status, data } (data is null when the
 * answer was not JSON). `body` is a FormData for uploads; `headers` are added
 * to the standard ones.
 */
export const request = async (method, url, body = undefined, headers = {}) => {
    const response = await fetch(url, {
        method,
        body,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), 'X-Requested-With': 'XMLHttpRequest', ...headers },
    });

    let data = null;

    try {
        data = await response.json();
    } catch {
        // Not JSON (a gateway error page, say); the status still tells the story.
    }

    return { ok: response.ok, status: response.status, data };
};

/**
 * Sends `payload` as JSON.
 */
export const requestJson = (method, url, payload) => request(method, url, JSON.stringify(payload), { 'Content-Type': 'application/json' });

/**
 * Sends `payload` as JSON and saves the file that comes back (a generated document). Answers
 * { ok, status, data } like `request`; on failure `data` is the server's JSON error, if it sent one.
 */
export const downloadFile = async (method, url, payload, fallbackName = 'download') => {
    const response = await fetch(url, {
        method,
        body: JSON.stringify(payload),
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
    });

    if (!response.ok) {
        let data = null;

        try {
            data = await response.json();
        } catch {
            // Not JSON; the status still tells the story.
        }

        return { ok: false, status: response.status, data };
    }

    const name = /filename="?([^";]+)"?/.exec(response.headers.get('Content-Disposition') ?? '')?.[1] ?? fallbackName;
    const link = document.createElement('a');
    link.href = URL.createObjectURL(await response.blob());
    link.download = name;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);

    return { ok: true, status: response.status, data: null };
};

/**
 * The first message of a failed answer: a validation error, the server's own
 * message, or a generic line.
 */
export const errorMessage = (result) => {
    const errors = result.data?.errors;

    return (errors && Object.values(errors).flat()[0]) || result.data?.message || 'Something went wrong. Please try again.';
};
