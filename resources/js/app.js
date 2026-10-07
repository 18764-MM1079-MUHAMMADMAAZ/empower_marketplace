// Triggers a browser download of client-generated text (the Dashboard "What's next" checklist's
// sign-off form, training checklist and .ics calendar invite — none of these need a server round
// trip since their content is already rendered server-side into the click handler).
window.empowerDownloadText = function (filename, text, mime) {
    const blob = new Blob([text], { type: mime || 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
};

// Reusable Alpine component for the billing/practice address fields: suggests full street
// addresses as you type (Photon, OpenStreetMap-based) and autofills city/state from a typed
// ZIP (Zippopotam.us). Both are free, keyless public APIs — see workflow-changes.md §5.
//
// Usage: x-data="addressAutocomplete({ address1: 'billingAddress1', city: 'billingCity', state: 'billingState', zip: 'billingZip' })"
// `city`/`state`/`zip` are optional — omit them for a single freeform address field (the wizard's
// practice-address screen), in which case a picked suggestion fills `address1` with the full
// "street, city, state zip" line instead of fanning out into separate fields.
document.addEventListener('alpine:init', () => {
    Alpine.data('addressAutocomplete', (fields) => ({
        query: '',
        suggestions: [],
        open: false,
        highlightedIndex: -1,
        loading: false,
        debounceTimer: null,

        init() {
            this.query = this.$wire.get(fields.address1) || '';
        },

        onAddressInput() {
            this.$wire.set(fields.address1, this.query, false);
            clearTimeout(this.debounceTimer);
            const q = this.query.trim();
            if (q.length < 4) {
                this.suggestions = [];
                this.open = false;
                return;
            }
            this.debounceTimer = setTimeout(() => this.fetchSuggestions(q), 250);
        },

        async fetchSuggestions(q) {
            this.loading = true;
            try {
                const url = 'https://photon.komoot.io/api/?q=' + encodeURIComponent(q)
                    + '&limit=8&lang=en&layer=house&layer=street&bbox=-171,18,-66,72';
                const response = await fetch(url);
                const data = await response.json();
                this.suggestions = (data.features || [])
                    .map((feature) => this.toSuggestion(feature.properties || {}))
                    .filter((suggestion) => suggestion.street);
            } catch (error) {
                this.suggestions = [];
            }
            this.loading = false;
            this.open = this.suggestions.length > 0;
            this.highlightedIndex = -1;
        },

        toSuggestion(properties) {
            const street = [properties.housenumber, properties.street].filter(Boolean).join(' ');

            return {
                street,
                city: properties.city || properties.town || properties.village || '',
                state: properties.state || '',
                zip: properties.postcode || '',
                label: [street, properties.city, properties.state, properties.postcode].filter(Boolean).join(', '),
            };
        },

        select(suggestion) {
            this.open = false;
            this.suggestions = [];

            if (fields.city) {
                this.query = suggestion.street;
                this.$wire.set(fields.address1, suggestion.street);
                this.flash(fields.address1);
                if (suggestion.city) {
                    this.$wire.set(fields.city, suggestion.city);
                    this.flash(fields.city);
                }
                if (suggestion.state) {
                    this.$wire.set(fields.state, suggestion.state);
                    this.flash(fields.state);
                }
                if (suggestion.zip && fields.zip) {
                    this.$wire.set(fields.zip, suggestion.zip);
                    this.flash(fields.zip);
                }
            } else {
                this.query = suggestion.label;
                this.$wire.set(fields.address1, suggestion.label);
                this.flash(fields.address1);
            }
        },

        async onZipInput(typedValue) {
            if (!fields.zip) {
                return;
            }

            const zip = (typedValue || '').trim();
            if (!/^\d{5}$/.test(zip)) {
                return;
            }

            try {
                const response = await fetch('https://api.zippopotam.us/us/' + zip);
                if (!response.ok) {
                    return;
                }
                const data = await response.json();
                const place = data.places && data.places[0];
                if (!place) {
                    return;
                }
                if (fields.city) {
                    this.$wire.set(fields.city, place['place name']);
                    this.flash(fields.city);
                }
                if (fields.state) {
                    this.$wire.set(fields.state, place['state abbreviation']);
                    this.flash(fields.state);
                }
            } catch (error) {
                // Offline or unreachable — the practice can still type city/state manually.
            }
        },

        flash(fieldName) {
            this.$nextTick(() => {
                const el = this.$root.querySelector('[data-ac-field="' + fieldName + '"]');
                if (el) {
                    el.animate(
                        [{ backgroundColor: '#eaf6ff' }, { backgroundColor: '' }],
                        { duration: 900, easing: 'ease-out' },
                    );
                }
            });
        },

        move(delta) {
            if (!this.open || !this.suggestions.length) {
                return;
            }
            const count = this.suggestions.length;
            this.highlightedIndex = (this.highlightedIndex + delta + count) % count;
        },

        chooseHighlighted() {
            if (this.highlightedIndex >= 0 && this.suggestions[this.highlightedIndex]) {
                this.select(this.suggestions[this.highlightedIndex]);
            }
        },

        close() {
            this.open = false;
            this.highlightedIndex = -1;
        },
    }));

    // Persistent offline banner (components/layouts/app.blade.php) — a global store rather than a
    // per-page listener, since it's one banner shared across the whole app, not a per-screen
    // concern. Matches the prototype's own navigator.onLine + online/offline listener approach.
    Alpine.store('connectivity', { online: navigator.onLine });
    window.addEventListener('online', () => { Alpine.store('connectivity').online = true; });
    window.addEventListener('offline', () => { Alpine.store('connectivity').online = false; });
});

import './admin-tour';
