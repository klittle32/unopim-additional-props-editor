import test from 'node:test';
import assert from 'node:assert/strict';
import { createState, changes, payload, failureMessage } from '../../resources/assets/editor.js';

const snapshot = () => ({version: 'v1', attributes: {editable: true, rows: [{name: 'Material', value: 'Steel'}], message: null}, features: {editable: true, items: ['First', 'Second'], message: null}});

test('CR and CRLF sections are preserved as read-only without blocking siblings', () => {
    for (const text of ['First\rSecond', 'First\r\nSecond']) {
        const source = snapshot();
        source.attributes.rows[0].value = text;
        const state = createState(source);
        assert.equal(state.original.attributes.editable, false);
        assert.match(state.original.attributes.message, /carriage return/i);
        state.features.push('New');
        assert.deepEqual(changes(state), {features: ['First', 'Second', 'New']});
        assert.equal(state.attributes[0].value, text);
    }
    const source = snapshot();
    source.features.items = ['CR\r'];
    assert.equal(createState(source).original.features.editable, false);
    source.attributes.rows[0].name = 'Name\r';
    assert.equal(createState(source).original.attributes.editable, false);
});

test('multiline controls preserve LF and redirected sessions explain recovery', async () => {
    let Editor;
    globalThis.HTMLElement = class {};
    globalThis.customElements = {get: () => undefined, define: (_, ctor) => { Editor = ctor; }};
    try {
        await import('../../resources/assets/editor.js?dom-regression');
        const editor = new Editor();
        editor.node = tag => ({tag, addEventListener() {}});
        const control = editor.input('Feature', 'First\nSecond', () => {}, {});
        assert.equal(control.tag, 'textarea');
        assert.equal(control.value, 'First\nSecond');
        const originalFetch = globalThis.fetch;
        editor.dataset = {endpoint: '/fixture', csrf: 'synthetic-session-token'};
        globalThis.fetch = async (_, options) => {
            assert.equal(options.headers['X-CSRF-TOKEN'], 'synthetic-session-token');
            return {redirected: false, ok: true, json: async () => snapshot()};
        };
        try {
            await editor.requestSnapshot('PATCH', {version: 'v1', changes: {}});
            globalThis.fetch = async () => ({redirected: true, ok: true, json: async () => ({})});
            await assert.rejects(editor.requestSnapshot('GET'), /session has ended/i);
        }
        finally { globalThis.fetch = originalFetch; }
    } finally {
        delete globalThis.HTMLElement;
        delete globalThis.customElements;
    }
});

test('opening is clean and drafts do not mutate the snapshot', () => {
    const source = snapshot();
    const state = createState(source);
    assert.deepEqual(changes(state), {});
    state.attributes[0].value = 'Wood';
    assert.equal(source.attributes.rows[0].value, 'Steel');
    assert.deepEqual(payload(state), {version: 'v1', changes: {attributes: [{name: 'Material', value: 'Wood'}]}});
});

test('feature order and removals serialize without unchanged siblings', () => {
    const state = createState(snapshot());
    state.features.reverse();
    assert.deepEqual(changes(state), {features: ['Second', 'First']});
    state.features = [];
    assert.deepEqual(changes(state), {features: []});
});

test('unsupported sections are never sent even if draft is modified', () => {
    const source = snapshot();
    source.attributes = {editable: false, rows: [], message: 'Unsupported data'};
    const state = createState(source);
    state.attributes.push({name: 'Unsafe', value: 'replacement'});
    state.features.push('Third');
    assert.deepEqual(changes(state), {features: ['First', 'Second', 'Third']});
});

test('reverting all edits returns to clean and serialization preserves strings', () => {
    const state = createState(snapshot());
    state.features.push('<script>not HTML</script>');
    assert.equal(payload(state).changes.features[2], '<script>not HTML</script>');
    state.features.pop();
    assert.deepEqual(changes(state), {});
});

test('duplicates are rejected rather than collapsed', () => {
    const state = createState(snapshot());
    state.attributes.push({name: 'Material', value: 'Wood'});
    assert.throws(() => payload(state), /unique/i);
});

test('failures explain recovery and never change version or drafts', () => {
    const state = createState(snapshot());
    state.features.push('Unsaved');
    for (const status of [401, 403, 419, 409, 422, 500]) {
        assert.ok(failureMessage(status, {errors: {'changes.features.0': ['Invalid feature']}}));
        assert.equal(state.version, 'v1');
        assert.equal(state.features.at(-1), 'Unsaved');
    }
    assert.match(failureMessage(409), /reload/i);
    assert.match(failureMessage(422, {errors: {name: ['Duplicate name']}}), /Duplicate name/);
});
