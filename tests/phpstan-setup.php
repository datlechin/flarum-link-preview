<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

use Flarum\Testing\integration\Setup\SetupScript;
use Flarum\Testing\integration\UsesTmpDir;

require __DIR__.'/../vendor/autoload.php';

/*
 * PHPStan analyses this extension against a booted Flarum, and `flarum/phpstan`
 * boots one from its bootstrap file. Since 2.0.0-rc.8 that bootstrap installs
 * the test site itself when it finds no config, which sounds harmless and is
 * not: PHPStan loads the bootstrap in every parallel worker, so several
 * installers race for one directory and one database. One wins, the rest die
 * with `Flarum\Install\StepFailed`, and the run is reported as an analysis
 * error rather than as what it is.
 *
 * Doing it here instead means it happens once, in one process, before PHPStan
 * starts. The reusable backend workflow runs `composer test:setup` before the
 * test job for the same reason; it just does not do so before the PHPStan job.
 */
$tmp = (new class() {
    use UsesTmpDir;
})->tmpDir();

// Only when there is nothing there. `SetupScript` drops every table to
// guarantee a clean install, so running it unconditionally would wipe a
// working test database on every static analysis.
if (file_exists("$tmp/config.php")) {
    return;
}

(new SetupScript())->run();
