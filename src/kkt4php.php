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

namespace kkt4php;

/**
 * Класс работы с терминалом ШТРИХ-М
 *
 * @author vovka
 */
class KKT {

    static $DEBUG = false;
    static $TIMEOUT_BYTE = 50; // ms

    const STX = 0x02;
    const ENQ = 0x05;
    const ACK = 0x06;
    const NAK = 0x15;

    private $socket;
    private $host;
    private $port;
    private $password;

    static public function debug($data) {
        if (KKT::$DEBUG) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            echo "{$trace[1]["class"]}->{$trace[1]["function"]}: " . ( is_string($data) ? $data : var_export($data, true)) . "\n";
        }
    }

    function __construct($host, $port, $password) {
        $this->host = $host;
        $this->port = $port;
        $this->password = $password;
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$this->socket) {
            throw new errors\SocketError();
        }
        KKT::debug("Создан socket = {$this->socket}");
    }

    function connect() {
        if (socket_connect($this->socket, $this->host, $this->port)) {
            $this->setTimeout();
            return true;
        } else {
            throw new errors\SocketError();
        }
    }

    private function write($buf, $len = null) {
        $bytes = socket_write($this->socket, $buf, $len ?? strlen($buf));
        if ($bytes === false) {
            throw new errors\SocketError();
        }
        KKT::debug(implode(" ", unpack("H*", $buf)));
        return $bytes;
    }

    private function read($len) {
        $buf = socket_read($this->socket, $len, PHP_BINARY_READ);
        if ($buf === false) {
            throw new errors\SocketError();
        }
        KKT::debug(implode(" ", unpack("H*", $buf)));
        return $buf;
    }

    function setTimeout($milis = null) {
        $ms = $milis ?? KKT::$TIMEOUT_BYTE;
        $timeout = socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, [
            "sec" => intval($ms % 1000),
            "usec" => ($ms - 1000 * intdiv($ms, 1000)) * 1000
        ]);
        if ($timeout === false) {
            throw new errors\SocketError();
        }
        return $timeout;
    }

    /**
     * Прочитать 1 байт
     * @return type
     */
    private function readByte() {
        $buf = $this->read(1);
        $bytes = unpack("c", $buf);
        return $bytes[1];
    }

    /**
     * Запись байта
     * @param type $byte
     * @return type
     */
    private function writeByte($byte) {
        return $this->write(pack("c", $byte));
    }

    /**
     * Записать данные и получить ответ 
     * @param type $buf
     * @return type
     */
    private function confirm($buf) {
        $this->write($buf);
        return $this->readByte();
    }

    function send(commands\Command $command) {
        switch ($this->confirm(pack("c", KKT::ENQ))) {
            case KKT::ACK :
                KKT::debug("ACK");
                break;
            case KKT::NAK :
                for ($i = 0; $i < 10; $i++) {
                    $c = $this->confirm(pack("c", KKT::STX) . $command->pack());
                    if ($c == KKT::ACK) {
                        KKT::debug("ACK");
                        for ($j = 0; $j < 10; $j++) {
                            if ($this->readByte() == KKT::STX) {
                                KKT::debug("STX");
                                $len = $this->readByte();
                                $buf = $this->read($len);
                                $checksum = $this->readByte();
                                if ($checksum == KKT::xor(pack("c", $len) . $buf)) {
                                    KKT::debug("LRC");
                                    $this->writeByte(KKT::ACK);
                                    $command->parse($buf);
                                    return $buf;
                                } else {
                                    throw new errors\KKTLRC();
                                }
                            }
                        }
                    }
                }
                break;
        }
    }

    /**
     * Исключающее ИЛИ 
     * @param type $buf
     * @return type
     */
    static function xor($buf) {
        $checksum = 0;
        $checkbuf = str_split(unpack("H*", $buf)[1], 2);
        foreach ($checkbuf as $byte) {
            $checksum ^= hexdec($byte);
        }
        return $checksum;
    }

}

namespace kkt4php\commands;

use kkt4php\KKT;

abstract class Command {

    static $CODE = "00";
    private $password;
    public $data;

    function __construct($password) {
        $this->password = $password;
    }

    function pack() {
        $len = 5;
        $buf = pack("cHV", $len, static::$CODE, $this->password);
        return $buf . pack("c", \kkt4php\KKT::xor($buf));
    }

    /**
     * Распаковка данных результата из буфера
     * @param string $buf 
     */
    abstract function parse($buf);
}

/**
 * ПолучитьКороткийЗапросСостоянияККМ
 */
class GetShortECRStatus extends Command {

    static $CODE = "10";

    const ERRORS = [
        0 => "Успешное выполнение команды",
        1 => "Неизвестная команда, неверный формат посылки или неизвестные параметры",
        2 => "Неверное состояние ФН",
        3 => "Ошибка ФН",
        4 => "Ошибка КС",
        5 => "Закончен срок эксплуатации ФН",
        6 => "Архив ФН переполнен",
        7 => "Неверные дата и/или время",
        8 => "Нет запрошенных данных",
        9 => "Некорректное значение параметров команды",
        16 => "Превышение размеров TLV данных",
        17 => "Нет транспортного соединения",
        18 => "Исчерпан ресурс КС",
        20 => "Исчерпан ресурс хранения",
        21 => "Исчерпан ресурс Ожидания передачи сообщения",
        22 => "Продолжительность смены более 24 часов",
        23 => "Неверная разница во времени между 2 операцими",
        32 => "Сообщение от ОФД не может быть принято",
        38 => "Вносимая клиентом сумма меньше суммы чека",
        43 => "Невозможно отменить предыдущую команду",
        44 => "Обнулённая касса",
        45 => "Сумма чека по секции меньше суммы сторно",
        46 => "В ККТ нет денег для выплаты",
        48 => "ККТ заблокирован, ждет ввода пароля налогового инспектора",
        50 => "Требуется выполнение общего гашения",
        51 => "Некорректные параметры в команде",
        52 => "Нет данных",
        53 => "Некорректный параметр при данных настройках",
        54 => "Некорректные параметры в команде для данной реализации ККТ",
        55 => "Команда не поддерживается в данной реализации ККТ",
        56 => "Ошибка в ПЗУ",
        57 => "Внутренняя ошибка ПО ККТ",
        58 => "Переполнение накопления по надбавкам в смене",
        59 => "Переполнение накопления в смене",
        60 => "Смена открыта – операция невозможна",
        61 => "Смена не открыта – операция невозможна"
    ];

    static public $MODE = [
        1 => "Выдача данных",
        2 => "Открытая смена, 24 часа не кончились",
        3 => "Открытая смена, 24 часа кончились",
        4 => "Закрытая смена",
        5 => "Блокировка по неправильному паролю налогового инспектора",
        6 => "Ожидание подтверждения ввода даты",
        7 => "Разрешение изменения положения десятичной точки",
        0b00001000 => "Приход",
        0b00011000 => "Расход",
        0b00101000 => "Возврат прихода",
        0b01001000 => "Возврат расхода",
        0b10001000 => "Нефискальный",
    ];
    static public $SUBMODE = [
        0 => "Бумага есть",
        1 => "Пассивное отсутствие бумаги",
        2 => "Активное отсутствие бумаги",
        3 => "После активного отсутствия бумаги",
        4 => "Фаза печати операции полных фискальных отчетов",
        5 => "Фаза печати операции"
    ];
    static public $LAST_PRINT = [
        0 => "Печать завершена успешно",
        1 => "Произошел обрыв бумаги",
        2 => "Ошибка принтера",
        5 => "Идет печать"
    ];
    static public $FLAGS = [
        0b0000000000000001 => "Рулон операционного журнала",
        0b0000000000000010 => "Рулон чековой ленты",
        0b0000000000000100 => "Верхний датчик подкладного документа",
        0b0000000000001000 => "Нижний датчик подкладного документа",
        0b0000000000010000 => "Положение десятичной точки 2",
        0b0000000001000000 => "Оптический датчик операционного журнала",
        0b0000000010000000 => "Оптический датчик чековой ленты",
        0b0000000100000000 => "Рычаг термоголовки операционного журнала опущен",
        0b0000001000000000 => "Рычаг термоголовки чековой ленты опущен",
        0b0000010000000000 => "Крышка корпуса ККТ поднята",
        0b0000100000000000 => "Денежный ящик открыт",
        0b0001000000000000 => "Отказ правого датчика принтера",
        0b0010000000000000 => "Отказ левого датчика принтера",
        0b1000000000000000 => "Увеличенная точность количества"
    ];

    static public function flags($data) {
        $f = [];
        foreach (self::$FLAGS as $b => $title) {
            if ($data & $b) {
                $f[] = $title;
            }
        }
        return $f;
    }

    public function parse($buf) {
        if (strlen($buf) == 16) {
            $data = unpack("C/CE/CN/vF/CM/CMM/CCa/CV1/CV2/C/C/CCb/C/C/CR", $buf);
        } else {
            $data = unpack("C/CE/CN/vF/CM/CMM/CCa/CV1/CV2/C/C/CCb/C/C/C/CR", $buf);
        }
        KKT::debug($data);
        $this->data = [
            "Код ошибки" => self::ERRORS[$data["E"]],
            "Порядковый номер кассира" => $data["N"],
            "Флаги" => GetShortECRStatus::flags($data["F"]),
            "Режим" => self::$MODE[$data["M"]],
            "Подрежим" => self::$SUBMODE[$data["MM"]],
            "Количество операций в чеке" => 256 * $data["Ca"] + $data["Cb"],
            "Напряжение резервной батареи" => round($data["V1"] / 51, 2),
            "Напряжение источника питания" => round($data["V2"] / 9, 2),
            "Результат последней печати" => self::$LAST_PRINT[$data["R"]]
        ];
    }

}

namespace kkt4php\errors;

class SocketError extends \Error {

    function __construct() {
        parent::__construct(socket_strerror(socket_last_error()));
    }

}

class KKTLRC extends \Error {
    
}
