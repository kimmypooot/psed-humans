/**
 * One colour per status for the charts, in the order a mechanism goes: grey until routed, blues
 * while PSED works on it (darker the closer to the Chief), amber for signing, red for the agency's
 * turn, green when approved. Same story as the badge tones, with enough contrast between slices.
 */
export const statusColors = {
    'Received by PSED': '#8a8d9e',
    'Routed to Action Officer': '#8f98e0',
    'Processing/Evaluating': '#4b55b5',
    'For PSED Chief Review': '#2a338f',
    'Reviewed by PSED Chief': '#d99a1b',
    'For Compliance': '#c11421',
    Approved: '#1a7f4b',
};
