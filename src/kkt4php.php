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

    static $DEBUG        = false;
    static $TIMEOUT_BYTE = 3000; // ms

    const STX = 0x02;
    const ENQ = 0x05;
    const ACK = 0x06;
    const NAK = 0x15;

    private $socket;
    private $host;
    private $port;
    private $password;
    private $connected = false;

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
        61 => "Смена не открыта – операция невозможна",
        89 => "Документ открыт другим кассиром",
        94 => "Неверная операция",
        115 => "Операция невозможна в открытом чеке данного типа",
        126 => "Неверное значение в поле длины"
    ];

    /**
     * Вывод отладочной информации
     * @param mixed $data
     */
    static public function debug($data) {
        if (KKT::$DEBUG) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            echo "{$trace[1]["class"]}->{$trace[1]["function"]}: " . ( is_string($data) ? $data : var_export($data, true)) . "\n";
        }
    }

    /**
     * Создаёт экземпляр драйвера
     * @param string $host IP-адрес ККМ
     * @param int $port Порт соединения 
     * @param int $password Пароль для последующих команд 
     * @throws errors\SocketError
     */
    function __construct($host, int $port, int $password = null) {
        $this->host     = $host;
        $this->port     = $port;
        $this->password = $password;
        $this->socket   = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$this->socket) {
            throw new errors\SocketError();
        }
        KKT::debug("Создан socket = {$this->socket}");
        $this->setTimeout();
    }

    /**
     * 
     */
    function __destruct() {
        $this->disconnect();
    }

    /**
     * Подключение к ККМ
     * @return kkt4php\KKT
     * @throws errors\SocketError
     */
    function connect() {
        if ($this->connected = socket_connect($this->socket, $this->host, $this->port)) {
            return $this;
        } else {
            throw new errors\SocketError();
        }
    }

    /**
     * Завершение соединения
     */
    function disconnect() {
        if ($this->socket) {
            KKT::debug("{$this->socket}");
            socket_set_option($this->socket, SOL_SOCKET, SO_LINGER, ["l_linger" => 0, "l_onoff" => 1]);
            socket_close($this->socket);
            $this->socket = null;
        }
    }

    private function write($buf, $len = null) {
        KKT::debug(implode(" ", unpack("H*", $buf)));
        $bytes = socket_write($this->socket, $buf, $len ?? strlen($buf));
        if ($bytes === false) {
            throw new errors\SocketError();
        }
        KKT::debug("[$bytes]");
        return $bytes;
    }

    private function read($len) {
        $buf = socket_read($this->socket, $len, PHP_BINARY_READ);
        if ($buf === false) {
            throw new errors\SocketError();
        }
        KKT::debug("[$len]" . implode(" ", unpack("H*", $buf)));
        return $buf;
    }

    /**
     * Установка ограничение времен ожидания
     * @param type $milis
     * @return type
     * @throws errors\SocketError
     */
    function setTimeout($milis = null) {
        $ms   = $milis ?? KKT::$TIMEOUT_BYTE;
        $time = [
            "sec" => intdiv($ms, 1000),
            "usec" => ($ms - 1000 * intdiv($ms, 1000)) * 1000
        ];
        if (socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, $time) === false) {
            throw new errors\SocketError();
        }
        if (socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, $time) === false) {
            throw new errors\SocketError();
        }
        KKT::debug($time);
        return $timeout;
    }

    /**
     * Прочитать 1 байт
     * @return type
     */
    private function readByte() {
        $buf   = $this->read(1);
        $bytes = unpack("C", $buf);
        return $bytes[1];
    }

    /**
     * Запись байта
     * @param type $byte
     * @return type
     */
    private function writeByte($byte) {
        return $this->write(pack("C", $byte));
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

    /**
     * Посылает данную команду на ККМ
     * @param \kkt4php\commands\Command $command
     * @return \kkt4php\KKT
     * @throws kkt4php\errors\WrongLRC
     */
    function send(commands\Command $command) {
        if (!$this->connected) {
            $this->connect();
        }
        $command->setPassword($this->password);
        for ($k = 0; $k < 10; $k++) {
            switch ($this->confirm(pack("C", KKT::ENQ))) {
                case KKT::ACK :
                    KKT::debug("ACK");
                    break;
                case KKT::NAK :
                    for ($i = 0; $i < 10; $i++) {
                        $c = $this->confirm(pack("C", KKT::STX) . $command->pack());
                        if ($c == KKT::ACK) {
                            KKT::debug("получен ACK");
                            for ($j = 0; $j < 10; $j++) {
                                if ($this->readByte() == KKT::STX) {
                                    KKT::debug("получен STX");
                                    $len      = $this->readByte();
                                    KKT::debug("получена длина $len");
                                    $buf      = $this->read($len);
                                    $checksum = $this->readByte();
                                    if ($checksum == KKT::xor(pack("C", $len) . $buf)) {
                                        KKT::debug("получен верный LRC");
                                        $this->writeByte(KKT::ACK);
                                        $command->parse($buf);
                                        return $this;
                                    } else {
                                        throw new errors\WrongLRC();
                                    }
                                }
                            }
                        }
                    }
                    break;
            }
        }
        $this->disconnect();
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

    /**
     * Посылка команды
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments) {
        $command_class = "kkt4php\\commands\\{$name}";
        KKT::debug($command_class);
        if (class_exists($command_class)) {
            $command = new $command_class(...$arguments);
            $this->send($command);
            return $command->getData();
        }
    }

    /**
     * Устанавливает пароль для последющих команд
     * @param int $password
     */
    function setPassword(int $password) {
        $this->password = $password;
    }

}

namespace kkt4php\commands;

use kkt4php\KKT,
    kkt4php\errors\KKTError;

abstract class Command {

    static $CODE = "00";
    private $init_password;
    private $password;
    protected $data;

    /**
     * Создаёт команду
     * @param string $password Пароль администратора или кассира
     */
    function __construct(int $password = null) {
        $this->password      = $password;
        $this->init_password = $password != null;
    }

    /**
     * Устанавливает пароль, если он не был установлен во время создания команды
     * @param int $password
     */
    function setPassword(int $password) {
        if ($password != null && !$this->init_password) {
            $this->password = $password;
        }
    }

    /**
     * Возвращает данные результата команды
     * @return array
     */
    function getData() {
        return $this->data;
    }

    /**
     * Упаковывает запрос
     * @param type $data
     * @return string Бинарная строка
     */
    function pack($data = "") {
        $len   = strlen($data) + 5;
        $buf   = pack("CCV", $len, static::$CODE, $this->password) . $data;
        $bytes = $buf . pack("c", \kkt4php\KKT::xor($buf));
        return $bytes;
    }

    /**
     * Распаковка данных обычного результата из буфера
     * @param string $buf
     */
    public function parse($buf) {
        if (strlen($buf) == 2) {
            $data       = unpack("C/Cerror", $buf);
            $this->data = [
                "Код ошибки" => $data["error"],
                "Ошибка" => KKT::ERRORS[$data["error"]],
                "Порядковый номер кассира" => "нет данных",
            ];
            throw new KKTError($this->data["Ошибка"], $this->data["Код ошибки"]);
        } else {
            $data       = unpack("C/Cerror/Cnumber", $buf);
            $this->data = [
                "Код ошибки" => $data["error"],
                "Ошибка" => KKT::ERRORS[$data["error"]],
                "Порядковый номер кассира" => $data["number"],
            ];
            return substr($buf, 3);
        }
    }

    /**
     * Упаковка целого значения в 5 байт
     * @param long64 $value
     * @return string
     */
    protected function packInteger5($value) {
        return pack("V", $value) . pack("c", 0);
    }

    /**
     * Упаковка целого 16 bit со знаком
     * @param int $value
     * @return string
     */
    protected function packSignedShort(int $value) {
        return pack("v", $value) | ($value < 0) ? 0b1000000000000000 : 0;
    }

}

/**
 * ПолучитьКороткийЗапросСостоянияККМ
 */
class GetShortECRStatus extends Command {

    static $CODE              = 0x10;
    static public $MODE       = [
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
    static public $SUBMODE    = [
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
    static public $FLAGS      = [
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

    /**
     * Интерпретирует поле флагов
     * @param short int $data Беззнаковое целое 2 байта
     * @return string
     */
    static public function flags($data) {
        $f = [];
        foreach (self::$FLAGS as $b => $title) {
            if ($data & $b) {
                $f[] = $title;
            }
        }
        return $f;
    }

    /**
     * Распаковка данных результата из буфера
     * @param string $buf Бинарная строка
     */
    public function parse($buf) {
        $rest = parent::parse($buf);
        KKT::debug(unpack("H*", $rest));
        if (strlen($rest) == 13) {
            $data = unpack("vflags/Cmode/Csubmode/Ccheck1/CV1/CV2/C/C/Ccheck2/C/C/Cresult", $rest);
        } else {
            $data = unpack("vflags/Cmode/Csubmode/Ccheck1/CV1/CV2/C/C/Ccheck2/C/C/C/Cresult", $rest);
        }
        KKT::debug($data);
        $this->data = array_merge($this->data, [
            "Флаги" => GetShortECRStatus::flags($data["flags"]),
            "Режим" => self::$MODE[$data["mode"]],
            "Подрежим" => self::$SUBMODE[$data["submode"]],
            "Количество операций в чеке" => 256 * $data["check1"] + $data["check2"],
            "Напряжение резервной батареи" => round($data["V1"] / 51, 2),
            "Напряжение источника питания" => round($data["V2"] / 9, 2),
            "Результат последней печати" => self::$LAST_PRINT[$data["result"]]
        ]);
    }

}

/**
 * Команда прихода
 */
class Sale extends Command {

    static $CODE = 0x80;
    protected $quantity;
    protected $price;
    protected $department;
    protected $tax1;
    protected $tax2;
    protected $tax3;
    protected $tax4;
    protected $text;

    /**
     * Команда производит регистрацию продажи определенного количества 
     * товара в определенную секцию с вычислением налогов без закрытия чека.
     * @param int $quantity Количество
     * @param int $price Цена
     * @param int $department Отдел
     * @param int $tax1 Налоговая группа 1
     * @param int $tax2 Налоговая группа 2
     * @param int $tax3 Налоговая группа 3
     * @param int $tax4 Налоговая группа 4
     * @param string $text Строка названия товара
     * @param string $password Пароль
     */
    function __construct($quantity, $price, $department, $tax1, $tax2, $tax3, $tax4, $text, $password = null) {
        parent::__construct($password);
        $this->quantity   = $quantity;
        $this->price      = $price;
        $this->department = $department;
        $this->tax1       = $tax1;
        $this->tax2       = $tax2;
        $this->tax3       = $tax3;
        $this->tax4       = $tax4;
        $this->text       = $text;
    }

    /**
     * Упаковывает запрос
     * @param type $data
     * @return string Бинарная строка
     */
    function pack($data = "") {
        return parent::pack(
                        //pack("H*", "E80300000064000000000100000000C1F3EBEAE00000000000000000000000000000000000000000000000000000000000000000000000")
                        $this->packInteger5($this->quantity) .
                        $this->packInteger5($this->price) .
                        pack("CCCCCa*",
                                $this->department,
                                $this->tax1,
                                $this->tax2,
                                $this->tax3,
                                $this->tax4,
                                str_pad($this->text, max(strlen($this->text), 40, "\0"))
                        )
        );
    }

}

/**
 * Команда закрытия чека
 */
class CloseCheck extends Command {

    static $CODE = 0x85;
    protected $summ1;
    protected $summ2;
    protected $summ3;
    protected $summ4;
    protected $discount;
    protected $tax1;
    protected $tax2;
    protected $tax3;
    protected $tax4;
    protected $text;

    /**
     * Команда закрытия чека. Метод производит закрытие чека комбинированным 
     * типом оплаты с вычислением налогов и суммы сдачи.
     * @param int $summ1
     * @param int $summ2
     * @param int $summ3
     * @param int $summ4
     * @param float $discount Скидка/Надбавка в процентах
     * @param int $tax1 Налоговая группа 1
     * @param int $tax2 Налоговая группа 1
     * @param int $tax3 Налоговая группа 1
     * @param int $tax4 Налоговая группа 1
     * @param string $text Строка названия товара
     * @param string $password Пароль
     */
    function __construct($summ1, $summ2, $summ3, $summ4, $discount, $tax1, $tax2, $tax3, $tax4, $text, $password = null) {
        $this->summ1    = $summ1;
        $this->summ2    = $summ2;
        $this->summ3    = $summ3;
        $this->summ4    = $summ4;
        $this->discount = intval($discount * 100);
        $this->tax1     = $tax1;
        $this->tax2     = $tax2;
        $this->tax3     = $tax3;
        $this->tax4     = $tax4;
        $this->text     = $text;
    }

    /**
     * Упаковывает запрос
     * @param type $data
     * @return string Бинарная строка
     */
    function pack($data = "") {
        return parent::pack(
                        $this->packInteger5($this->sum1) .
                        $this->packInteger5($this->sum2) .
                        $this->packInteger5($this->sum3) .
                        $this->packInteger5($this->sum4) .
                        $this->packSignedShort($this->discount) .
                        pack("CCCCa*",
                                $this->tax1,
                                $this->tax2,
                                $this->tax3,
                                $this->tax4,
                                $this->text
                        )
        );
    }

}

/**
 * Команда отмены (аннулирования) чека
 */
class CancelCheck extends Command {

    static $CODE = 0x88;

}

class CutCheck extends Command {

    static $CODE      = 0x25;
    static $TYPE_FULL = 0;
    static $TYPE_PART = 1;
    protected $type;

    function __construct($type = 0, int $password = null) {
        parent::__construct($password);
        $this->type = $type;
    }

    public function pack($data = ""): string {
        return parent::pack(pack("C", $this->type));
    }

}

class OpenCheck extends Command {

    static $CODE             = 0x8D;
    static $TYPE_SALE        = 0;
    static $TYPE_BUY         = 1;
    static $TYPE_CANCEL_SALE = 2;
    static $TYPE_CANCEL_BUY  = 3;

    function __construct($type = 0, int $password = null) {
        parent::__construct($password);
        $this->type = $type;
    }

    public function pack($data = ""): string {
        return parent::pack(pack("C", $this->type));
    }

}

class FeedDocument extends Command {

    static $CODE              = 0x8D;
    static $FLAG_JOURNAL      = 0x00;
    static $FLAG_RECEIPT      = 0x01;
    static $FLAG_SLIPDOCUMENT = 0x02;
    protected $flags;
    protected $lines;

    function __construct($lines = 0, $flags = 0, int $password = null) {
        parent::__construct($password);
        $this->flags = $flags;
    }

}

class Beep extends Command {

    static $CODE = 0x13;

}

/**
 * Метод передает команду «E0h», при этом в ФП открывается смена, а ККТ переходит в режим «Открытой смены»
 */
class OpenSession extends Command {

    static $CODE = 0xE0;

}

class GetDeviceMetrics extends Command {

    static $CODE      = 0xFC;
    static $TYPES     = [
        "ККМ",
        "Весы",
        "Фискальная память",
        "КУ ТРК",
        "MemoPlus",
        "Чековый принтер",
        "АСПД"
    ];
    static $LANGUAGES = [
        "русский",
        "английский",
        "эстонский",
        "казахский",
        "белорусский",
        "армянский",
        "грузинский",
        "украинский",
        "киргизский",
        "туркменский",
        "молдавский"
    ];

    /**
     * Упаковывает запрос
     * @param type $data
     * @return string Бинарная строка
     */
    function pack($data = "") {
        $buf = pack("CC", 1, static::$CODE);
        return $buf . pack("c", \kkt4php\KKT::xor($buf));
    }

    /**
     * Распаковка данных результата из буфера
     * @param string $buf Бинарная строка
     */
    public function parse($buf) {
        $data       = unpack("C/Cerror/Ctype/Csubtype/Cversion/Crevision/Cmodel/Clanguage/A*text", $buf);
        $this->data = [
            "Код ошибки" => $data["error"],
            "Ошибка" => KKT::ERRORS[$data["error"]],
            "Тип устройства" => self::$TYPES [$data["type"]],
            "Подтип устройства" => $data["subtype"],
            "Версия протокола" => $data["version"],
            "Подверсия протокола" => $data["revision"],
            "Модель устройства" => $data["model"],
            "Язык устройства" => self::$LANGUAGES[$data["language"]],
            "Название устройства" => iconv("CP1251", "UTF-8", $data["text"])
        ];
    }

}

namespace kkt4php\errors;

class KKTError extends \Error {
    
}

class SocketError extends KKTError {

    function __construct() {
        parent::__construct(socket_strerror(socket_last_error()));
        socket_clear_error();
    }

}

class WrongLRC extends KKTError {
    
}

class NoPassword extends KKTError {
    
}
