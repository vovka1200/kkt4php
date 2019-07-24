<?php

/*
 * Copyright (C) 2019 vovka
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

$response = $t->OpenCheck(kkt4php\commands\OpenCheck::$TYPE_SALE);
var_export($response);

$response = $t->Sale(1, 1, 1, 0, 0, 0, 0, "test");
var_export($response);

$response = $t->FeedDocument(3);
var_export($response);

$response = $t->CutCheck(\kkt4php\commands\CutCheck::$TYPE_PART);
var_export($response);

$response = $t->CancelCheck();
var_export($response);
