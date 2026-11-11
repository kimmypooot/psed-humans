/** How each kind of notice looks: the icon in its chip, and a short name for the filter and screen readers. */
export const kindMeta = {
    submitted: { icon: 'upload', label: 'New submission' },
    resubmitted: { icon: 'refresh', label: 'Corrected file' },
    routed: { icon: 'arrowRight', label: 'Routed to you' },
    forwarded: { icon: 'shield', label: 'For your review' },
    reviewed: { icon: 'checkCircle', label: 'Approved by Chief' },
    returned: { icon: 'exclamation', label: 'For compliance' },
    approved: { icon: 'checkCircle', label: 'Final approval' },
    due_soon: { icon: 'clock', label: 'Due soon' },
    overdue: { icon: 'exclamation', label: 'Overdue' },
};

export const metaFor = (kind) => kindMeta[kind] ?? { icon: 'bell', label: 'Notice' };

/** "just now", "5 min ago", "3 h ago", "Yesterday", or the date once it is older than a week. */
export const timeAgo = (iso) => {
    const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);

    if (seconds < 45) return 'just now';
    if (seconds < 3600) return `${Math.max(1, Math.round(seconds / 60))} min ago`;
    if (seconds < 86400) return `${Math.round(seconds / 3600)} h ago`;
    if (seconds < 172800) return 'Yesterday';
    if (seconds < 7 * 86400) return `${Math.round(seconds / 86400)} days ago`;

    return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
};

/** The heading a notice sits under on the page: Today, Yesterday, or the date. */
export const dayLabel = (iso) => {
    const day = new Date(iso);
    const today = new Date();
    const startOf = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
    const diff = Math.round((startOf(today) - startOf(day)) / 86400000);

    if (diff <= 0) return 'Today';
    if (diff === 1) return 'Yesterday';

    return day.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
};
