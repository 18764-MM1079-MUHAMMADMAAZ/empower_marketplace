// Guided tour of the admin dashboard, built on driver.js. driver.js (and its stylesheet) are
// imported on demand so the library is only downloaded when the tour actually starts.
const SEEN_KEY = 'empower-admin-tour-seen';

const STEPS = [
    {
        element: '[data-tour="sidebar"]',
        popover: {
            title: 'Navigation',
            description: 'Everything you manage lives here: submissions, documents, packages, discount codes, leads, orders, logs and more.',
            side: 'right',
        },
    },
    {
        element: '[data-tour="bell"]',
        popover: {
            title: 'Notifications',
            description: 'New sign-ups, payments and intake submissions show up here with an unread badge. Click one to jump straight to it.',
            side: 'bottom',
        },
    },
    {
        element: '[data-tour="stats"]',
        popover: {
            title: 'At a glance',
            description: 'Submissions waiting for review, total orders, stale documents and new leads. Each card links to its list.',
            side: 'bottom',
        },
    },
    {
        element: '[data-tour="purchase-gating"]',
        popover: {
            title: 'Purchase gating',
            description: 'Set the date purchases open. Until then, unpaid clients see "Sign up for updates" instead of the payment form. "Open now" lifts the gate immediately.',
            side: 'top',
        },
    },
    {
        element: '[data-tour="call-booking"]',
        popover: {
            title: 'Specialist call booking',
            description: 'Turn client call booking on or off, edit the time slots and topics, and review every request under "View requests".',
            side: 'top',
        },
    },
    {
        element: '[data-tour="ai-usage"]',
        popover: {
            title: 'OpenAI usage',
            description: 'Daily API calls over the last two weeks, so unexpected spikes in document processing are easy to spot.',
            side: 'top',
        },
    },
    {
        element: '[data-tour="account"]',
        popover: {
            title: 'Your account',
            description: 'Manage your profile or log out from here. You can replay this tour any time with the "Take a tour" button.',
            side: 'bottom',
        },
    },
];

const isVisible = (selector) => {
    const el = document.querySelector(selector);

    return el !== null && el.getClientRects().length > 0;
};

export async function startAdminTour({ auto = false } = {}) {
    if (auto) {
        try {
            if (localStorage.getItem(SEEN_KEY)) {
                return;
            }
        } catch (e) {
            // storage unavailable: still offer the tour this once
        }
    }

    const [{ driver }] = await Promise.all([import('driver.js'), import('driver.js/dist/driver.css')]);

    // Skip steps whose element isn't on screen (e.g. the sidebar is hidden on mobile).
    const steps = STEPS.filter((step) => isVisible(step.element));

    const markSeen = () => {
        try {
            localStorage.setItem(SEEN_KEY, '1');
        } catch (e) {
            // ignore
        }
    };

    const tour = driver({
        animate: true,
        smoothScroll: true,
        showProgress: true,
        allowClose: true,
        overlayOpacity: 0.6,
        stagePadding: 6,
        stageRadius: 14,
        popoverClass: 'empower-tour',
        nextBtnText: 'Next &rarr;',
        prevBtnText: '&larr; Back',
        doneBtnText: 'Done',
        progressText: '{{current}} of {{total}}',
        steps,
        onDestroyed: markSeen,
    });

    tour.drive();
}

window.startAdminTour = startAdminTour;
