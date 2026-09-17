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

const sandbox = {};
vm.createContext(sandbox);
vm.runInContext(code, sandbox);

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

console.log(`\n${passed}/${passed} windows-form checks passed`);
