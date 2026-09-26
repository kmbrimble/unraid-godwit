// Extracts the dependency-free windows-form functions from plugin/Godwit.page
// (between the GODWIT_WINDOWS_FORM_BEGIN/END markers) and exercises them
// directly, since the page's JS otherwise has no execution harness. Run via
// `node tests/windows_form_test.mjs` — wired into `php tests/run.php` so the
// one documented test command still covers it.
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const page = readFileSync(path.join(__dirname, '..', 'plugin', 'Godwit.page'), 'utf8');
const start = page.indexOf('// GODWIT_WINDOWS_FORM_BEGIN');
const end = page.indexOf('// GODWIT_WINDOWS_FORM_END');
if (start === -1 || end === -1) {
    throw new Error('GODWIT_WINDOWS_FORM_BEGIN/END markers not found in plugin/Godwit.page');
}
const code = page.slice(start, end);

const treeStart = page.indexOf('// GODWIT_TREE_BEGIN');
const treeEnd = page.indexOf('// GODWIT_TREE_END');
if (treeStart === -1 || treeEnd === -1) {
    throw new Error('GODWIT_TREE_BEGIN/END markers not found in plugin/Godwit.page');
}
const treeCode = page.slice(treeStart, treeEnd);

const sandbox = {};
vm.createContext(sandbox);
vm.runInContext(code, sandbox);
vm.runInContext(treeCode, sandbox);

let passed = 0;

function t(name, fn) {
    fn();
    passed++;
    console.log('PASS: ' + name);
}

t('godwitDayLabels is Sun..Sat in index order', () => {
    assert.equal(JSON.stringify(sandbox.godwitDayLabels), JSON.stringify(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']));
});

t('godwitHalfHourGrid has 48 half-hourly slots covering the full day', () => {
    const grid = sandbox.godwitHalfHourGrid();
    assert.equal(grid.length, 48);
    assert.equal(grid[0], '00:00');
    assert.equal(grid[1], '00:30');
    assert.equal(grid[47], '23:30');
});

t('the default midnight-wrap window round-trips through rows unchanged', () => {
    const original = [{ days: [0, 1, 2, 3, 4, 5, 6], start: '22:00', end: '06:00', limit_mbit: 250 }];
    const rows = sandbox.godwitWindowsToRows(original);
    const windows = sandbox.godwitRowsToWindows(rows);
    assert.equal(JSON.stringify(windows), JSON.stringify(original));
    // Key order must match the stored schema (days, start, end, limit_mbit)
    // or a byte-identical save is impossible even with equal values.
    assert.equal(JSON.stringify(Object.keys(windows[0])), JSON.stringify(['days', 'start', 'end', 'limit_mbit']));
});

t('start == end ("all day") round-trips unchanged, not rejected or coerced', () => {
    const original = [{ days: [2], start: '09:00', end: '09:00', limit_mbit: 100 }];
    const windows = sandbox.godwitRowsToWindows(sandbox.godwitWindowsToRows(original));
    assert.equal(JSON.stringify(windows), JSON.stringify(original));
    assert.equal(sandbox.godwitValidateRow(windows[0]), null);
});

t('an off-grid time (not on the half-hourly grid) is preserved, never silently snapped', () => {
    const original = [{ days: [3], start: '22:15', end: '06:45', limit_mbit: 50 }];
    const windows = sandbox.godwitRowsToWindows(sandbox.godwitWindowsToRows(original));
    assert.equal(JSON.stringify(windows), JSON.stringify(original));
});

t('a non-array days throws (this is why Apply-JSON must reject it before calling godwitWindowsToRows)', () => {
    assert.throws(() => sandbox.godwitRowsToWindows(sandbox.godwitWindowsToRows([{ days: 'not-an-array', start: '22:00', end: '06:00', limit_mbit: 100 }])));
});

t('days are sorted ascending on the way back out', () => {
    const row = { days: [5, 0, 3], start: '22:00', end: '06:00', limit_mbit: 100 };
    const window = sandbox.godwitRowToWindow(row);
    assert.equal(JSON.stringify(window.days), JSON.stringify([0, 3, 5]));
});

t('godwitValidateRow rejects a window with no day ticked', () => {
    assert.match(sandbox.godwitValidateRow({ days: [], start: '22:00', end: '06:00', limit_mbit: 100 }), /day/);
});

t('godwitValidateRow rejects a non-positive or non-numeric limit', () => {
    assert.match(sandbox.godwitValidateRow({ days: [0], start: '22:00', end: '06:00', limit_mbit: 0 }), /positive/);
    assert.match(sandbox.godwitValidateRow({ days: [0], start: '22:00', end: '06:00', limit_mbit: -1 }), /positive/);
    assert.match(sandbox.godwitValidateRow({ days: [0], start: '22:00', end: '06:00', limit_mbit: 'abc' }), /positive/);
});

t('godwitValidateRow rejects malformed start/end but accepts start==end', () => {
    assert.match(sandbox.godwitValidateRow({ days: [0], start: '25:00', end: '06:00', limit_mbit: 100 }), /HH:MM/);
    assert.equal(sandbox.godwitValidateRow({ days: [0], start: '09:00', end: '09:00', limit_mbit: 100 }), null);
});

// --- Phase 4 tri-state tree selection (godwitTreeToggle etc.) --------------

t('a fresh, unticked node with no included ancestor becomes its own include root on check', () => {
    const r = sandbox.godwitTreeToggle({ path: 'Documents', is_dir: true }, [], []);
    assert.equal(JSON.stringify(r.included), JSON.stringify([{ path: 'Documents', is_dir: true }]));
    assert.equal(r.excluded.length, 0);
});

t('un-ticking an explicitly included node removes it from included, not adds it to excluded', () => {
    const included = [{ path: 'Documents', is_dir: true }];
    const r = sandbox.godwitTreeToggle({ path: 'Documents', is_dir: true }, included, []);
    assert.equal(r.included.length, 0);
    assert.equal(r.excluded.length, 0);
});

t('un-ticking a child of an included folder adds it to excluded, leaves the parent include intact', () => {
    const included = [{ path: 'Documents', is_dir: true }];
    const r = sandbox.godwitTreeToggle({ path: 'Documents/Drafts', is_dir: true }, included, []);
    assert.equal(JSON.stringify(r.included), JSON.stringify(included));
    assert.equal(JSON.stringify(r.excluded), JSON.stringify([{ path: 'Documents/Drafts', is_dir: true }]));
});

t('re-ticking a previously excluded child removes it from excluded (falls back to the ancestor include)', () => {
    const included = [{ path: 'Documents', is_dir: true }];
    const excluded = [{ path: 'Documents/Drafts', is_dir: true }];
    const r = sandbox.godwitTreeToggle({ path: 'Documents/Drafts', is_dir: true }, included, excluded);
    assert.equal(r.excluded.length, 0);
    assert.equal(JSON.stringify(r.included), JSON.stringify(included));
});

t('removing an included root drops any exclusions nested under it — they would otherwise dangle, matching the server-side validation rule', () => {
    const included = [{ path: 'Documents', is_dir: true }];
    const excluded = [{ path: 'Documents/Drafts', is_dir: true }];
    const r = sandbox.godwitTreeToggle({ path: 'Documents', is_dir: true }, included, excluded);
    assert.equal(r.included.length, 0);
    assert.equal(r.excluded.length, 0, 'dangling exclusion under a removed include must be dropped, not left orphaned');
});

t('godwitTreeNodeChecked: false with no ancestor and not itself included', () => {
    assert.equal(sandbox.godwitTreeNodeChecked('Pictures', [{ path: 'Documents', is_dir: true }], []), false);
});

t('godwitTreeNodeChecked: true for a node implied by an included ancestor', () => {
    assert.equal(sandbox.godwitTreeNodeChecked('Documents/Taxes/2025.pdf', [{ path: 'Documents', is_dir: true }], []), true);
});

t('godwitTreeNodeChecked: false for an explicitly excluded child of an included ancestor', () => {
    const included = [{ path: 'Documents', is_dir: true }];
    const excluded = [{ path: 'Documents/Drafts', is_dir: true }];
    assert.equal(sandbox.godwitTreeNodeChecked('Documents/Drafts', included, excluded), false);
    assert.equal(sandbox.godwitTreeNodeChecked('Documents/Drafts/todo.txt', included, excluded), false);
});

t('a sibling folder is unaffected by an unrelated include/exclude pair', () => {
    const included = [{ path: 'Documents', is_dir: true }];
    const excluded = [{ path: 'Documents/Drafts', is_dir: true }];
    assert.equal(sandbox.godwitTreeNodeChecked('Pictures/holiday.jpg', included, excluded), false);
});

// v0.6.3: the tree dialog's Done was at the bottom of the scroll area and the
// only visible control (the titlebar close) silently discarded edits.
t('godwitTreeIsDirty: identical selection is clean, order-insensitive', () => {
    const a = { included: [{ path: 'A', is_dir: true }, { path: 'B', is_dir: true }], excluded: [] };
    const b = { included: [{ path: 'B', is_dir: true }, { path: 'A', is_dir: true }], excluded: [] };
    assert.equal(sandbox.godwitTreeIsDirty(a, b), false);
});

t('godwitTreeIsDirty: an added include, removed include or new exclude is dirty', () => {
    const saved = { included: [{ path: 'A', is_dir: true }], excluded: [] };
    assert.equal(sandbox.godwitTreeIsDirty({ included: [{ path: 'A', is_dir: true }, { path: 'B', is_dir: true }], excluded: [] }, saved), true);
    assert.equal(sandbox.godwitTreeIsDirty({ included: [], excluded: [] }, saved), true);
    assert.equal(sandbox.godwitTreeIsDirty({ included: saved.included, excluded: [{ path: 'A/x', is_dir: true }] }, saved), true);
});

const treeDialogHtml = page.slice(page.indexOf('<div id="tree-dialog"'), page.indexOf('</div>', page.indexOf('id="tree-root"')));
t('tree dialog: commit/cancel live in the dialog button pane, not the scrolling content', () => {
    assert.ok(!treeDialogHtml.includes('tree-done'), 'Done must not be inside the scrolling #tree-dialog content');
    const init = page.slice(page.indexOf("$('#tree-dialog').dialog({"));
    const opts = init.slice(0, init.indexOf('});') );
    assert.ok(/buttons\s*:/.test(opts) && /Done/.test(opts) && /Cancel/.test(opts), 'dialog buttons Done + Cancel');
    assert.ok(/beforeClose\s*:/.test(opts), 'beforeClose dirty guard');
});

t('tree dialog: expand affordance is CSS-drawn, no bare unicode triangle glyphs', () => {
    assert.ok(!/[▸▾]/.test(page), 'no ▸/▾ glyphs left in Godwit.page');
    assert.ok(page.includes('.godwit-tree-expand::before'), 'CSS triangle rule present');
});

console.log(`\n${passed}/${passed} windows-form + tree-selection checks passed`);
