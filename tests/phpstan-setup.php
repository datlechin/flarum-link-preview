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

$tmp = (new class() {
    use UsesTmpDir;
})->tmpDir();

if (file_exists("$tmp/config.php")) {
    return;
}

(new SetupScript())->run();
