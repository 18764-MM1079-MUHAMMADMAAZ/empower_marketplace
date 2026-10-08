// Guided tours for the admin panel, built on driver.js. driver.js (and its stylesheet) are
// imported on demand so the library is only downloaded when a tour actually starts.
//
// Each page's tour is a list of steps keyed by route name (see <body data-admin-page>); a step
// points at an element marked with data-tour="…" in the Blade views. Steps whose element isn't on
// screen (e.g. the sidebar on mobile, or a row that doesn't exist yet) are skipped.
const SEEN_KEY = 'empower-admin-tour-seen';

const step = (target, title, description, side = 'bottom') => ({
    element: `[data-tour="${target}"]`,
    popover: { title, description, side },
});

const sidebar = step('sidebar', 'Navigation', 'Everything you manage lives here. The sidebar stays in place on every page.', 'right');
const bell = step('bell', 'Notifications', 'New sign-ups, payments, intake submissions and client replies appear here. Click one to jump straight to it.');
const account = step('account', 'Your account', 'Manage your profile or log out. You can replay this tour on any page with the "Take a tour" button.');

const search = (what) => step('search', 'Search', `Type to find ${what}. Results update as you type.`);
const exportStep = (what) => step('export', 'Export to Excel', `Downloads ${what} as a spreadsheet, respecting the filters you've applied.`);
const table = (description) => step('table', 'The list', description, 'top');

export const TOURS = {
    'admin.dashboard': [
        sidebar,
        bell,
        step('stats', 'At a glance', 'Submissions waiting for review, total orders, stale documents and new leads. Each card links to its list.'),
        step('purchase-gating', 'Purchase gating', 'Set the date purchases open. Until then, unpaid clients see "Sign up for updates" instead of the payment form. "Open now" lifts the gate immediately.', 'top'),
        step('call-booking', 'Specialist call booking', 'Turn client call booking on or off, edit the time slots and topics, and review every request under "View requests".', 'top'),
        step('ai-usage', 'OpenAI usage', 'Daily API calls over the last two weeks, so unexpected spikes in document processing are easy to spot.', 'top'),
        account,
    ],
    'admin.submissions': [
        sidebar,
        step('filter', 'Filter by status', 'Switch between Submitted, Under Review, Approved and Rejected. The number beside each tab is how many submissions are in it.'),
        search('a submission by practice name or email'),
        table('Each row is a client submission. Open one to review the intake answers, uploads and generated documents. Professional and Advanced submissions arrive already Under Review.'),
    ],
    'admin.submissions.show': [
        sidebar,
        step('uploads', 'Uploaded forms', 'Everything the client uploaded, with its AI review status.', 'top'),
        step('answers', 'Practice intake answers', 'The client\'s answers to the intake questions, section by section.', 'top'),
        step('lms', 'Empower LMS', 'Shows whether the client\'s training account was created in Moodle and which courses they\'re enrolled in. Use the button to provision or retry.', 'top'),
        step('document-review', 'Document review', 'Review, regenerate or replace the generated documents before approving.', 'top'),
        step('questions', 'Questions for the client', 'Ask the client a question and see every question and answer in one thread. Replies appear here automatically.', 'top'),
        step('decision', 'Review decision', 'Approve the submission, or send it back for resubmission with notes.', 'top'),
    ],
    'admin.orders': [
        sidebar,
        step('date-range', 'Date range', 'Limit the list to orders placed between two dates.'),
        step('filter', 'Status filter', 'Show only orders in a particular status.'),
        search('an order by client name or email'),
        exportStep('the orders'),
        step('finance-report', 'Send Finance Report', 'Emails yesterday\'s new and cancelled orders to Finance right now, the same report that goes out automatically each morning.'),
        table('Each row is an order. You can mark an order as processed for Finance or open it to edit.'),
    ],
    'admin.leads': [
        sidebar,
        search('a lead by name, email or practice'),
        step('filter', 'Source filter', 'Tell contact-form enquiries apart from people who signed up for updates, and leads you added by hand.'),
        exportStep('the leads'),
        step('new', 'Add a lead', 'Create a lead manually, for example from a phone call or a referral.'),
        table('Each row shows who they are, what they asked about and where they came from. Mark leads as contacted once you\'ve followed up.'),
    ],
    'admin.specialist-calls': [
        sidebar,
        step('filter', 'Filter by status', 'Show only Pending, Scheduled, Completed or Cancelled call requests.'),
        exportStep('the call requests'),
        table('Requested calls, soonest first, with the client\'s phone number and topic. Times are Eastern.'),
        step('row-status', 'Change the status', 'Moving a call to Scheduled or Cancelled emails the client automatically. Completed and Pending send nothing.', 'left'),
    ],
    'admin.intake-questions': [
        sidebar,
        step('page-title', 'Practice intake questions', 'The workflow questions clients answer one at a time in the intake wizard.'),
        step('sections', 'Sections', 'Questions are grouped by section. Open a section to see and manage its questions.', 'top'),
    ],
    'admin.documents': [
        sidebar,
        step('filter', 'Filter documents', 'Narrow the list by document status.'),
        table('Generated compliance documents across all clients. Stale documents need regenerating after a client changes their answers.'),
    ],
    'admin.packages': [
        sidebar,
        step('new', 'New package', 'Create a package with its pricing and included document types.'),
        table('Each package, its monthly and annual price and whether it\'s active on the public pricing page.'),
    ],
    'admin.discount-codes': [
        sidebar,
        step('new', 'New discount code', 'Create a percentage, fixed-amount or free-trial code.'),
        table('Every code with its usage so far. Use the row actions to edit a code or email it to a lead.'),
    ],
    'admin.users': [
        sidebar,
        step('filter', 'Filter by role', 'Show only clients or only admins.'),
        search('a user by name or email'),
        exportStep('the users'),
        step('new', 'Add a user', 'Create a client or admin account.'),
        table('All accounts. Edit a user to change their details or role.'),
    ],
    'admin.payment-logs': [
        sidebar,
        step('filter', 'Filter by status', 'Show only successful, failed or other payment attempts.'),
        search('a payment by email, name or transaction ID'),
        exportStep('the payment logs'),
        table('Every payment attempt with its gateway response. Open a row for the full detail.'),
    ],
    'admin.activity-log': [
        sidebar,
        step('filter', 'Filter by event type', 'Narrow the log to one kind of event, such as submissions, leads or LMS provisioning.'),
        search('an event by type or description'),
        exportStep('the activity log'),
        table('A running record of what happened, who did it and when. LMS failures show up here too.'),
    ],
};

// Pages without their own tour still get a short orientation.
const FALLBACK = [sidebar, bell, account];

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

    const page = document.body.dataset.adminPage;
    const steps = (TOURS[page] ?? FALLBACK).filter((s) => isVisible(s.element));

    if (steps.length === 0) {
        return;
    }

    const [{ driver }] = await Promise.all([import('driver.js'), import('driver.js/dist/driver.css')]);

    const markSeen = () => {
        try {
            localStorage.setItem(SEEN_KEY, '1');
        } catch (e) {
            // ignore
        }
    };

    driver({
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
    }).drive();
}

window.startAdminTour = startAdminTour;
