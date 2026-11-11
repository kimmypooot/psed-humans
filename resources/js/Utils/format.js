export const formatDate = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '—');

export const formatDateTime = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—');

/** The words for a step recorded in the history of a mechanism. */
export const eventLabel = (action) =>
    ({
        submitted: 'Submitted',
        resubmitted: 'Submitted again',
        evaluated: 'Evaluated by PSED',
        returned: 'Returned for compliance',
        approved_for_signature: 'Approved for signature',
        approved: 'Final approval',
    })[action] ?? action;

/** Lets a long status such as "Processing/Evaluating" wrap after the slash instead of spilling out of its box. */
export const breakable = (text) => String(text).replaceAll('/', '/​');
