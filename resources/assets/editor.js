export function createState(snapshot) {
    if (typeof snapshot.version !== 'string' || !snapshot.attributes || !snapshot.features) {
        throw new Error('Invalid additional data response.');
    }
    snapshot = structuredClone(snapshot);
    for (const section of ['attributes', 'features']) {
        const values = section === 'attributes' ? snapshot.attributes.rows.flatMap(row => [row.name, row.value]) : snapshot.features.items;
        if (snapshot[section].editable && values.some(value => value.includes('\r'))) {
            snapshot[section].editable = false;
            snapshot[section].message = 'This section contains carriage return characters. It is preserved and read-only to avoid browser newline normalization.';
        }
    }
    return {
        version: snapshot.version,
        original: structuredClone(snapshot),
        attributes: structuredClone(snapshot.attributes.rows),
        features: [...snapshot.features.items],
    };
}

export function changes(state) {
    const result = {};
    if (state.original.attributes.editable && JSON.stringify(state.attributes) !== JSON.stringify(state.original.attributes.rows)) {
        result.attributes = state.attributes;
    }
    if (state.original.features.editable && JSON.stringify(state.features) !== JSON.stringify(state.original.features.items)) {
        result.features = state.features;
    }
    return result;
}

export function payload(state) {
    const changed = changes(state);
    if (changed.attributes) {
        const names = changed.attributes.map(row => row.name);
        if (names.some(name => !name.trim())) throw new Error('Specification names must not be empty.');
        if (new Set(names).size !== names.length) throw new Error('Specification names must be unique.');
    }
    return {version: state.version, changes: changed};
}

export function failureMessage(status, body = {}) {
    if (status === 401) return 'Your session has ended. Sign in again; your edits remain here.';
    if (status === 403) return 'You do not have permission to edit this data. Your edits remain here.';
    if (status === 419) return 'Your session token has expired. Open a new tab to sign in, or copy your edits before reloading this page.';
    if (status === 409) return 'This data changed elsewhere. Copy any edits you want to keep, then explicitly reload and discard your edits before trying again.';
    if (status === 422) {
        const errors = Object.values(body.errors || {}).flat().filter(value => typeof value === 'string');
        return errors.join(' ') || 'Some values are invalid. Check your entries and try again.';
    }
    return 'Unable to access additional data. Your edits have been kept. Please try again.';
}

// DOM is intentionally not touched during Node imports.
if (typeof customElements !== 'undefined' && !customElements.get('additional-props-editor')) {
    class AdditionalPropsEditor extends HTMLElement {
        connectedCallback() {
            if (this.initialized) return;
            this.initialized = true;
            this.unload = event => {
                if (this.dirty()) { event.preventDefault(); event.returnValue = ''; }
            };
            // In the pinned native navigation plugin, cancelling BEFORE forces a full
            // page load (it does NOT cancel navigation). Let beforeunload guard that load.
            this.navigate = event => { if (this.dirty()) event.preventDefault(); };
            window.addEventListener('beforeunload', this.unload);
            document.addEventListener('unopim:navigate:before', this.navigate);
            this.render();
            this.load();
        }

        disconnectedCallback() {
            window.removeEventListener('beforeunload', this.unload);
            document.removeEventListener('unopim:navigate:before', this.navigate);
            this.request?.abort();
            this.initialized = false;
        }

        dirty() { return Boolean(this.state && Object.keys(changes(this.state)).length); }

        node(tag, text, parent = this) {
            const node = document.createElement(tag);
            if (text !== undefined) node.textContent = text;
            parent.append(node);
            return node;
        }

        button(text, action, parent) {
            const button = this.node('button', text, parent);
            button.type = 'button';
            button.addEventListener('click', action);
            return button;
        }

        render() {
            this.replaceChildren();
            this.node('h2', 'Additional Product Data');
            this.node('p', 'Save this panel separately. The native product Save button does not save additional data.');
            this.status = this.node('p', 'Loading additional data…');
            this.status.setAttribute('role', 'status');
            this.status.setAttribute('aria-live', 'polite');
            this.error = this.node('p', '');
            this.error.setAttribute('role', 'alert');
            this.form = this.node('form');
            this.form.addEventListener('submit', event => { event.preventDefault(); event.stopPropagation(); this.save(); });
            this.fields = this.node('fieldset', undefined, this.form);
            this.fields.disabled = true;
            this.sections = this.node('div', undefined, this.fields);
            this.saveButton = this.node('button', 'Save additional data', this.form);
            this.saveButton.type = 'submit';
            this.reloadButton = this.button('Reload additional data', () => {
                if (this.dirty() && !window.confirm('Discard your unsaved additional data edits and reload?')) return;
                this.load();
            }, this.form);
            this.update();
        }

        update() {
            const dirty = this.dirty();
            this.fields.disabled = this.busy || !this.state;
            this.saveButton.disabled = this.busy || !dirty || this.conflict;
            this.reloadButton.disabled = Boolean(this.busy);
            this.reloadButton.textContent = dirty ? 'Reload and discard edits' : 'Reload additional data';
            this.setAttribute('aria-busy', this.busy ? 'true' : 'false');
            if (this.busy) this.status.textContent = this.saving ? 'Saving additional data…' : 'Loading additional data…';
            else if (this.state) this.status.textContent = dirty ? 'Unsaved additional data changes.' : 'Additional data is up to date.';
            else this.status.textContent = 'Additional data is unavailable. Use Reload to retry.';
        }

        input(label, value, onInput, parent) {
            const wrapper = this.node('label', label, parent);
            const input = this.node('textarea', undefined, wrapper);
            input.rows = 2;
            input.value = value;
            input.addEventListener('input', () => { onInput(input.value); this.update(); });
            return input;
        }

        drawSections(focus) {
            this.sections.replaceChildren();
            for (const section of ['attributes', 'features']) {
                const spec = section === 'attributes';
                const fieldset = this.node('fieldset', undefined, this.sections);
                this.node('legend', spec ? 'Additional specifications' : 'Features (ordered)', fieldset);
                const source = this.state.original[section];
                fieldset.disabled = !source.editable;
                if (source.message || !source.editable) this.node('p', source.message || 'This stored section is unsupported and cannot be edited.', fieldset);
                if (!source.editable) continue;
                const rows = this.state[section];
                rows.forEach((row, index) => {
                    const group = this.node('div', undefined, fieldset);
                    group.className = 'ape-row';
                    const input = this.input(spec ? `Specification ${index + 1} name` : `Feature ${index + 1}`, spec ? row.name : row,
                        value => { if (spec) row.name = value; else rows[index] = value; }, group);
                    input.dataset.section = section;
                    input.dataset.index = index;
                    if (spec) this.input(`Specification ${index + 1} value`, row.value, value => { row.value = value; }, group);
                    const actions = this.node('div', undefined, group);
                    actions.className = 'ape-actions';
                    if (!spec) {
                        for (const [label, offset] of [['up', -1], ['down', 1]]) {
                            const button = this.button(`Move feature ${index + 1} ${label}`, () => {
                                [rows[index], rows[index + offset]] = [rows[index + offset], rows[index]];
                                this.drawSections({section, index: index + offset});
                            }, actions);
                            button.disabled = index + offset < 0 || index + offset >= rows.length;
                        }
                    }
                    this.button(`Remove ${spec ? 'specification' : 'feature'} ${index + 1}`, () => {
                        rows.splice(index, 1);
                        this.drawSections({section, index: Math.max(0, index - 1)});
                    }, actions);
                });
                const add = this.button(spec ? 'Add specification' : 'Add feature', () => {
                    rows.push(spec ? {name: '', value: ''} : '');
                    this.drawSections({section, index: rows.length - 1});
                }, fieldset);
                add.dataset.add = section;
            }
            this.update();
            if (focus) {
                const target = this.sections.querySelector(`[data-section="${focus.section}"][data-index="${focus.index}"]`) || this.sections.querySelector(`[data-add="${focus.section}"]`);
                target?.focus();
            }
        }

        async requestSnapshot(method, data) {
            this.request = new AbortController();
            const headers = {Accept: 'application/json'};
            if (method === 'PATCH') {
                headers['Content-Type'] = 'application/json';
                const token = this.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.content;
                if (!token) throw new Error('The session token is missing. Copy your edits before reloading the page.');
                headers['X-CSRF-TOKEN'] = token;
            }
            const response = await fetch(this.dataset.endpoint, {
                method, headers, credentials: 'same-origin', signal: this.request.signal,
                ...(data ? {body: JSON.stringify(data)} : {}),
            });
            if (response.redirected) throw new Error(failureMessage(401));
            const body = await response.json().catch(() => ({}));
            if (!response.ok) {
                if (response.status === 409) this.conflict = true;
                throw new Error(failureMessage(response.status, body));
            }
            return createState(body);
        }

        async load() {
            if (this.busy) return;
            this.busy = true;
            this.error.textContent = '';
            this.update();
            try {
                const state = await this.requestSnapshot('GET');
                this.state = state;
                this.conflict = false;
                this.drawSections();
            } catch (error) {
                if (error.name !== 'AbortError') this.error.textContent = error.message || failureMessage(0);
            } finally { this.busy = false; this.update(); }
        }

        async save() {
            if (this.busy || !this.dirty() || this.conflict) return;
            this.error.textContent = '';
            let data;
            try { data = payload(this.state); }
            catch (error) { this.error.textContent = error.message; return; }
            this.busy = this.saving = true;
            this.update();
            try {
                this.state = await this.requestSnapshot('PATCH', data);
                this.drawSections();
            } catch (error) {
                if (error.name !== 'AbortError') this.error.textContent = error.message || failureMessage(0);
            } finally { this.busy = this.saving = false; this.update(); }
        }
    }
    customElements.define('additional-props-editor', AdditionalPropsEditor);
}
