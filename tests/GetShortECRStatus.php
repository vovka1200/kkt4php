<?php

/*
 * Copyright (C) 2019 Vladimir Yavorskiy <vovka@krevedko.su>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

require_once realpath(__DIR__ . "/../") . '/src/kkt4php.php';
require_once 'config.php';

use kkt4php\KKT;

KKT::$DEBUG = true;

$t = new KKT(HOST, PORT, PASSWORD);
try {
    $response = $t->GetShortECRStatus();
    var_export($response);
} finally {
    $t->disconnect();
}
