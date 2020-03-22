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

require_once 'config.php';

use kkt4php\KKT,
    kkt4php\commands\FeedDocument,
    kkt4php\commands\PrintStringWithFont,
    kkt4php\commands\CutCheck;

$t        = new KKT(HOST, PORT, PASSWORD);
$lines    = 10;
$t->PrintStringWithFont(str_pad("$lines", 40, "-"), 1, PrintStringWithFont::FLAG_RECEIPT);
$response = $t->FeedDocument($lines, FeedDocument::FLAG_RECEIPT);

$lines    = 5;
$t->PrintStringWithFont(str_pad("$lines", 40, "-"), 1, PrintStringWithFont::FLAG_RECEIPT);
$response = $t->FeedDocument($lines, FeedDocument::FLAG_RECEIPT);
$t->PrintStringWithFont(str_pad("-", 40, "-"), 1, PrintStringWithFont::FLAG_RECEIPT);

$response = $t->FeedDocument(3, FeedDocument::FLAG_RECEIPT);
$t->CutCheck(CutCheck::TYPE_PART);
