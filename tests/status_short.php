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

use kkt4php\KKT,
    kkt4php\commands\StatusShort;

KKT::$DEBUG = true;

$t = new KKT("127.0.0.1", 7778, "2");
$t->connect();
$c = new StatusShort(2);
$t->send($c);
var_export($c->data);

