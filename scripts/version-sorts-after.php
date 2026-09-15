<?php
/**
 * Returns true if $new sorts after $old under strcmp() — the exact function
 * dynamix.plugin.manager's `plugin` script uses to decide whether an
 * installed version is out of date. Unraid does NOT do semver comparison,
 * so "1.0.10" sorts BEFORE "1.0.9" under this rule. Keep version components
 * single-digit or zero-padded (see CLAUDE.md).
 */
function version_sorts_after(string $old, string $new): bool
{
    return strcmp($new, $old) > 0;
}

// CLI usage: php version-sorts-after.php <old> <new>
// Exit 0 if $new sorts after $old, exit 1 otherwise. Used by both the
// release workflow and tests/run.php so the guard logic has one home.
if (PHP_SAPI === 'cli' && realpath($argv[0]) === __FILE__) {
    if ($argc !== 3) {
        fwrite(STDERR, "usage: php version-sorts-after.php <old> <new>\n");
        exit(2);
    }
    exit(version_sorts_after($argv[1], $argv[2]) ? 0 : 1);
}
