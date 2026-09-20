/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_picker_browser_test.mjs
 * Module Type: Browser Model Regression Test
 * Purpose: Exercise the destination picker through a deterministic DOM seam.
 * Responsibilities:
 *   - Verify request and event behavior without claiming browser layout or assistive-technology coverage.
 * Actual picker-module runtime contracts using a small deterministic DOM seam.
 * Author: Rudolf Klusal
 * This is a Node event/request fixture, not evidence of browser layout or AT.
 */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

/**
 * Synthetic event fields exercised by the real picker; omitted booleans behave as false.
 * @typedef {Object} PickerEventFields
 * @property {boolean} [bubbles] Whether to dispatch to fixture ancestors.
 * @property {string} [key] Keyboard action under test.
 * @property {boolean} [ctrlKey] Control modifier.
 * @property {boolean} [shiftKey] Shift modifier.
 * @property {boolean} [altKey] Alt modifier.
 * @property {boolean} [metaKey] Command/Meta modifier.
 * @property {boolean} [isComposing] Provisional IME keystroke flag.
 * @property {Element} [target] Original event recipient.
 * @property {Element|null} [relatedTarget] Focus destination, or outside the fixture.
 */

/** Synthetic response row using exact decimal IDs and plain presentation text.
 * @typedef {{id:string,title:string,path:string,label:string,depth:number}} PickerFixtureRow
 */

/** Bounded response schema consumed by the picker, without application data.
 * @typedef {{ok:boolean,rows:PickerFixtureRow[],next_after_id:string|null}} PickerFixtureResponse
 */

/**
 * One intercepted browser request whose response is controlled by the test.
 * @typedef {Object} PickerFixtureRequest
 * @property {string} url Local request URL, inspected without opening a connection.
 * @property {{signal:AbortSignal,credentials:string,cache:string,headers:Record<string,string>}} options Production request ownership/options.
 * @property {(response:{ok:boolean,json:()=>Promise<PickerFixtureResponse>})=>void} resolve Supplies the deferred response.
 */

/** Fixture-only DOM element and event propagation required by the real module. */
class Element {
    /**
     * Initialize a detached synthetic element.
     * @param {string} tag Element name.
     * @return {void}
     */
    constructor(tag = 'div') {
        this.tagName = tag; this.dataset = {}; this.children = []; this.parent = null;
        this.attributes = {}; this.listeners = new Map(); this.hidden = false;
        this.value = ''; this.id = ''; this.textContent = ''; this.disabled = false;
        const classes = new Set();
        this.classList = {
            /** Record a class addition. @param {string} name Class token. @return {Set<string>} Updated internal membership. */
            add: (name) => classes.add(name),
            /** Record a class removal. @param {string} name Class token. @return {boolean} Whether membership existed. */
            remove: (name) => classes.delete(name),
            /** Query class membership. @param {string} name Class token. @return {boolean} Whether present. */
            contains: (name) => classes.has(name),
            /** Apply requested membership without simulating browser style calculation. @param {string} name Class token. @param {boolean} enabled Desired state. @return {Set<string>|boolean} Internal Set operation result, unused by production. */
            toggle: (name, enabled) => enabled ? classes.add(name) : classes.delete(name),
        };
        this.style = {
            /** Ignore presentation-only CSS assignments in this logical event fixture. @return {void} No layout is simulated. */
            setProperty() {},
        };
    }
    /** Resolve attachment through the fixture parent chain. @return {boolean} Connection to the fixture document. */
    get isConnected() { return this === document || Boolean(this.parent?.isConnected); }
    /** Record an attribute written by the real picker. @param {string} key Attribute name. @param {string} value Value. @return {void} */
    setAttribute(key, value) { this.attributes[key] = String(value); }
    /** Read a previously recorded fixture attribute. @param {string} key Attribute name. @return {string|undefined} Stored attribute. */
    getAttribute(key) { return this.attributes[key]; }
    /** Remove an attribute from the fixture node. @param {string} key Attribute name. @return {void} */
    removeAttribute(key) { delete this.attributes[key]; }
    /** Record the visible control's native validity message. @param {string} message Native form validity message. @return {void} */
    setCustomValidity(message) { this.validationMessage = message; }
    /** Reparent a child into this synthetic subtree. @param {Element} child Node to append. @return {Element} Appended node. */
    appendChild(child) {
        if (child.parent) child.parent.children = child.parent.children.filter(
            /** Remove the child from its old parent before reparenting. @param {Element} node Existing sibling. @return {boolean} Whether retained. */
            (node) => node !== child);
        child.parent = this; this.children.push(child); return child;
    }
    /** Append each supplied child in order. @param {...Element} nodes Nodes to append. @return {void} */
    append(...nodes) { nodes.forEach(
        /** Append the next fixture child in order. @param {Element} node Supplied child. @return {Element} Reparented child. */
        (node) => this.appendChild(node)); }
    /** Detach old children before installing the replacement subtree. @param {...Element} nodes Replacement children. @return {void} */
    replaceChildren(...nodes) {
        this.children.forEach(
            /** Detach each old child from this fixture parent. @param {Element} child Previous child. @return {void} Clears its connection. */
            (child) => { child.parent = null; });
        this.children = []; this.append(...nodes);
    }
    /** Check recursive containment within the disposable tree. @param {Element|null} node Possible descendant. @return {boolean} Containment. */
    contains(node) { return Boolean(node && (node === this || this.children.some(
        /** Search each descendant subtree for the same node identity. @param {Element} child Current child. @return {boolean} Whether it contains the target. */
        (child) => child.contains(node)))); }
    /** Match the limited data-attribute selectors used by the picker. @param {string} selector Data attribute selector. @return {boolean} Match. */
    matches(selector) {
        const dataKey = selector.slice(6, -1).replace(/-([a-z])/g,
            /** Convert fixture data-attribute segments to dataset casing. @param {string} _ Full hyphenated match. @param {string} letter Following letter. @return {string} Uppercase dataset letter. */
            (_, letter) => letter.toUpperCase());
        return Object.hasOwn(this.dataset, dataKey);
    }
    /** Walk parent nodes to find the nearest selector match. @param {string} selector Data attribute selector. @return {Element|null} Closest match. */
    closest(selector) { return this.matches(selector) ? this : this.parent?.closest(selector) || null; }
    /** Collect matching descendants in document order. @param {string} selector Data attribute selector. @return {Element[]} Descendant matches. */
    querySelectorAll(selector) {
        return this.children.flatMap(
            /** Include a matching child and recursively ordered matches beneath it. @param {Element} child Current descendant root. @return {Element[]} Matching subtree nodes. */
            (child) => [...(child.matches(selector) ? [child] : []), ...child.querySelectorAll(selector)]);
    }
    /** Return the first matching descendant or no match. @param {string} selector Data attribute selector. @return {Element|null} First match. */
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    /** Retain a listener and its optional abort lifetime. @param {string} type Event name. @param {(event:FakeEvent)=>void} fn Handler. @param {{signal?:AbortSignal}} options Lifetime cancellation; absent signal stays active. @return {void} */
    addEventListener(type, fn, options = {}) {
        const list = this.listeners.get(type) || [];
        list.push({fn, signal: options.signal}); this.listeners.set(type, list);
    }
    /** Dispatch local listeners and bubble until propagation stops. @param {FakeEvent} event Mutable event seam. @return {boolean} Default allowed. */
    dispatchEvent(event) {
        if (!event.target) event.target = this;
        for (const entry of this.listeners.get(event.type) || []) {
            if (!entry.signal?.aborted) entry.fn(event);
        }
        if (event.bubbles && !event.stopped && this.parent) this.parent.dispatchEvent(event);
        return !event.defaultPrevented;
    }
    /** Focus this fixture node without invoking a browser. @return {void} Dispatch focus after updating active element. */
    focus() { document.activeElement = this; this.dispatchEvent(new FakeEvent('focus')); }
}
/** Input identity used by instanceof checks in the real module. */
class Input extends Element {}
/** Button identity used by the fixture constructor. */
class Button extends Element {}
/** Keyboard, pointer and form event seam. */
class FakeEvent {
    /** Create a mutable keyboard/pointer event with explicit fixture fields. @param {string} type Event name. @param {PickerEventFields} options Optional event fields. @return {void} */
    constructor(type, options = {}) { this.type = type; Object.assign(this, options); }
    /** Track suppression of the event's default action. @return {void} Record default suppression. */
    preventDefault() { this.defaultPrevented = true; }
    /** Stop bubbling to ancestor fixture nodes. @return {void} Record propagation suppression. */
    stopPropagation() { this.stopped = true; }
}
const document = new Element('document');
document.body = new Element('body'); document.appendChild(document.body);
document.baseURI = 'http://localhost/index.php';
document.createElement =
    /** Preserve button identity when the production picker creates result nodes. @param {string} tag Element name. @return {Element} Detached fixture node. */
    (tag) => tag === 'button' ? new Button(tag) : new Element(tag);
const requests = [];
const timers = new Map();
let timerId = 0;
let observer = null;
const sandbox = {
    document, HTMLElement: Element, HTMLInputElement: Input, HTMLButtonElement: Button,
    Node: Element, Event: FakeEvent, AbortController, URL,
    MutationObserver:
    /** Capture document lifecycle observation without running a browser mutation queue. */
    class {
        /** Capture the production observer for explicit fixture delivery. @param {Function} callback Observer callback. @return {void} */
        constructor(callback) { observer = callback; }
        /** Leave observation delivery under deterministic fixture control. @return {void} Observation is manually driven by the fixture. */
        observe() {}
    },
    window: {
        location: {origin: 'http://localhost', href: 'http://localhost/index.php?page=admin'},
        /** Queue deferred picker work for explicit fixture advancement. @param {Function} callback Production deferred task. @return {number} Unique fixture handle. */
        setTimeout: (callback) => { timers.set(++timerId, callback); return timerId; },
        /** Revoke the exact queued fixture task. @param {number} id Timer handle. @return {boolean} Whether pending work existed. */
        clearTimeout: (id) => timers.delete(id),
    },
    /** Intercept a picker request without network access. @param {string} url Requested URL. @param {PickerFixtureRequest['options']} options Production fetch settings. @return {Promise<{ok:boolean,json:()=>Promise<PickerFixtureResponse>}>} Deferred response. */
    fetch: (url, options) => new Promise(
        /** Retain the response resolver so stale/current requests can complete out of order. @param {PickerFixtureRequest['resolve']} resolve Response resolver. @return {number} Queue size; Promise ignores this result. */
        (resolve) => requests.push({url, options, resolve})),
};
vm.createContext(sandbox);
const policySource = readFileSync(new URL('../public/assets/gallery-modules/gallery-picker-policy.js', import.meta.url), 'utf8');
vm.runInContext(policySource.replaceAll('export const ', 'const '), sandbox);
const source = readFileSync(new URL('../public/assets/gallery-modules/searchable-gallery-picker.js', import.meta.url), 'utf8');
vm.runInContext(source.replace(/^import .*gallery-picker-policy\.js[^\n]*\n/m, '')
    .replace('export function setupGallerySearchPickers', 'function setupGallerySearchPickers'), sandbox);

/** Build a fixture node with one production data-role marker. @param {string} key Data key. @param {string} tag Tag. @return {Element} Fixture element. */
function node(key, tag = 'div') {
    const result = tag === 'input' ? new Input(tag) : tag === 'button' ? new Button(tag) : new Element(tag);
    result.dataset[key] = ''; return result;
}
/** Mount a complete picker with an off-page committed selection. @return {{picker:Element,input:Input,hidden:Input,menu:Element,root:Button,status:Element,more:Button,clear:Button}} Complete dynamically insertable picker with an initial selection. */
function mount() {
    const picker = node('gallerySearchPicker');
    Object.assign(picker.dataset, {pageSize: '30', searchDelay: '200', searchUrl: '/index.php?page=admin_gallery_picker_search',
        loadingLabel: 'Loading', emptyLabel: 'Empty', errorLabel: 'Unavailable'});
    const input = node('gallerySearchPickerInput', 'input'); input.value = 'Committed /old (#9000)'; input.id = 'picker';
    const hidden = node('gallerySearchPickerValue', 'input'); hidden.value = '9000'; hidden.disabled = true;
    const menu = node('gallerySearchPickerMenu');
    const root = node('gallerySearchPickerOption', 'button');
    Object.assign(root.dataset, {gallerySearchPickerRoot: '', galleryId: '0', galleryLabel: 'No parent'}); root.id = 'root';
    menu.appendChild(root);
    const status = node('gallerySearchPickerStatus');
    const more = node('gallerySearchPickerMore', 'button');
    const clear = node('gallerySearchPickerClear', 'button');
    const help = node('gallerySearchPickerHelp'); help.textContent = 'Choose a parent or root.';
    picker.append(input, hidden, menu, status, more, clear, help); document.body.appendChild(picker);
    return {picker, input, hidden, menu, root, status, more, clear};
}
/** Deliver a bubbling keyboard or pointer event to the current control. @param {Element} target Event recipient. @param {string} type Event name. @param {PickerEventFields} options Optional event fields. @return {FakeEvent} Event. */
function fire(target, type, options = {}) {
    const event = new FakeEvent(type, {bubbles: true, ...options}); target.dispatchEvent(event); return event;
}
/** Run the current debounce queue exactly once. @return {void} Fire the currently scheduled debounce callbacks. */
function flushTimers() {
    const pending = [...timers.values()]; timers.clear(); pending.forEach(
        /** Invoke the task captured before the queue was cleared. @param {Function} callback Deferred picker work. @return {unknown} Task result, unused by this fixture. */
        (callback) => callback());
}
/** Create a safe response row with a disambiguating synthetic path. @param {number|string} id Result ID, including exact BIGINT strings. @return {PickerFixtureRow} Safe server result. */
function row(id) { return {id: String(id), title: 'Duplicate', path: 'branch/deep/' + id, label: 'Duplicate /branch/deep/' + id, depth: 2}; }
/** Resolve one deferred fetch and allow its microtasks to settle. @param {PickerFixtureRequest} request Deferred fetch. @param {PickerFixtureRow[]} rows Results. @param {number|string|null} next Exclusive cursor, or null for the final page. @return {Promise<void>} Settled module response. */
async function respond(request, rows, next = null) {
    request.resolve({ok: true,
        /** Decode the already bounded synthetic page. @return {Promise<PickerFixtureResponse>} Results and exact continuation. */
        json: async () => ({ok: true, rows, next_after_id: next === null ? null : String(next)})});
    for (let i = 0; i < 8; i += 1) await Promise.resolve();
}

const first = mount();
sandbox.setupGallerySearchPickers();
assert.equal(first.hidden.disabled, false);
assert.equal(first.hidden.value, '9000');
first.input.focus();
assert.equal(new URL(requests.at(-1).url).searchParams.get('q'), '', 'Committed path is context, not an implicit search query');
const stale = requests.at(-1);
first.input.value = 'typed';
fire(first.input, 'input');
assert.equal(first.hidden.value, '', 'Typing revokes the old committed ID synchronously');
assert.notEqual(first.input.validationMessage, '', 'Uncommitted parent text cannot silently submit as root');
assert.equal(stale.options.signal.aborted, true);
assert.equal(requests.length, 1, 'Typing is debounced');
flushTimers();
const current = requests.at(-1);
await respond(current, [row(101), row(102)]);
await respond(stale, [row(999)]);
assert.equal(first.menu.querySelectorAll('[data-gallery-search-picker-option]').length, 3);
assert.equal(first.menu.children[1].dataset.galleryId, '101', 'Ignored abort cannot let old response replace current options');
const accepted = fire(first.input, 'keydown', {key: 'Enter'});
assert.equal(accepted.defaultPrevented, true);
assert.equal(first.hidden.value, '101', 'Enter commits the first physical result, never an accidental root');
assert.equal(first.input.validationMessage, '', 'Explicit parent commitment restores validity');
assert.equal(first.menu.hidden, true);
assert.equal(sandbox.window.location.href, 'http://localhost/index.php?page=admin');

first.input.focus();
await respond(requests.at(-1), Array.from({length: 30},
    /** Fill one complete synthetic response page. @param {undefined} _ Empty array slot. @param {number} index Zero-based row offset. @return {PickerFixtureRow} Sequential result. */
    (_, index) => row(index + 1)), 30);
assert.equal(first.hidden.value, '101', 'Search never overwrites a committed off-page selection');
assert.equal(first.more.hidden, false);
fire(first.more, 'click');
assert.equal(new URL(requests.at(-1).url).searchParams.get('after_id'), '30');
await respond(requests.at(-1), [row(31)]);
assert.equal(first.menu.querySelectorAll('[data-gallery-search-picker-option]').length, 2, 'Next page replaces existing nodes');
fire(first.menu.children[1], 'click');
assert.equal(first.hidden.value, '31', 'Delegated events commit dynamically rendered options');

first.input.focus();
const escaping = requests.at(-1);
const escape = fire(first.input, 'keydown', {key: 'Escape'});
assert.equal(escape.stopped, true);
await respond(escaping, [row(1000)]);
assert.equal(first.menu.hidden, true, 'Escaped response cannot reopen picker');
assert.equal(fire(first.input, 'keydown', {key: 'Escape'}).stopped, undefined, 'Second Escape remains available to parent drawer');
const requestCount = requests.length;
fire(first.input, 'compositionstart');
first.input.value = 'composing';
fire(first.input, 'input'); flushTimers();
assert.equal(requests.length, requestCount, 'IME composition suppresses transient searches');
fire(first.input, 'compositionend'); flushTimers();
assert.equal(requests.length, requestCount + 1);
await respond(requests.at(-1), [row(222)]);
assert.equal(fire(first.input, 'keydown', {key: 'Enter', ctrlKey: true}).defaultPrevented, undefined, 'Modified Enter is preserved');
fire(first.root, 'click');
assert.equal(first.hidden.value, '0', 'Root requires explicit commitment');

first.input.focus();
const detached = requests.at(-1);
document.body.replaceChildren();
observer([{addedNodes: []}]);
assert.equal(detached.options.signal.aborted, true, 'Removing a panel disposes the old request');
await respond(detached, [row(555)]);
const replacement = mount();
observer([{addedNodes: [replacement.picker]}]);
replacement.input.focus();
await respond(requests.at(-1), [row(333)]);
fire(replacement.input, 'keydown', {key: 'Enter'});
assert.equal(replacement.hidden.value, '333', 'Injected replacement is bound without a whole-page rebind');
replacement.input.focus();
await respond(requests.at(-1), [row('9007199254740993')]);
fire(replacement.input, 'keydown', {key: 'Enter'});
assert.equal(replacement.hidden.value, '9007199254740993', 'BIGINT commitment is exact above the JavaScript safe-number limit');
const disabledWorkflow = mount();
disabledWorkflow.input.disabled = true;
observer([{addedNodes: [disabledWorkflow.picker]}]);
assert.equal(disabledWorkflow.hidden.disabled, true, 'Binding preserves workflow-owned disabled parent fields');
assert.equal(sandbox.window.location.href, 'http://localhost/index.php?page=admin');
console.log('PASS: actual picker module debounce, stale ownership, commitment, root, IME, pagination and fragment disposal (Node DOM seam).');
