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

use kkt4php\KKT,
    kkt4php\errors\KKTError,
    kkt4php\errors\Printing;

KKT::$DEBUG = true;

$t = new KKT(HOST, PORT, PASSWORD);

$response = $t->OpenCheck(kkt4php\commands\OpenCheck::$TYPE_SALE);
var_export($response);
try {
    // Продажа
    $response = $t->Sale(1000, 100, 1, 0, 0, 0, 0, "test Sale");
    var_export($response);
    // Закрытие чека
    $response = $t->CloseCheck(100, 0, 0, 0, 0, 0, 0, 0, 0, "test Close");
    var_export($response);


    for ($i = 0; $i < 5; $i ++) {
        try {
            // Пауза для печати
            sleep(1);
            // Продвижение на 3 строки
            $response = $t->FeedDocument(3);
            break;
        } catch (Printing $ex) {
            echo "Идёт печать...";
        }
    }

    for ($i = 0; $i < 5; $i ++) {
        try {
            // Пауза для печати
            sleep(1);
            // Продвижение на 3 строки
            $response = $t->CutCheck();
            break;
        } catch (Printing $ex) {
            echo "Идёт печать...";
        }
    }
} catch (KKTError $ex) {
    // Отмена чека
    echo $ex->getMessage() . "!\n";
    $response = $t->CancelCheck();
    var_export($response);
}

