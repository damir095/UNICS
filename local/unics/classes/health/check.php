<?php
namespace local_unics\health;

defined('MOODLE_INTERNAL') || die();

/**
 * Одна проверка здоровья системы.
 *
 * Дешевая проверка ходит только в свою БД и потому может считаться на каждой странице (полоса
 * тревоги). Дорогая ходит по сети и запускается лишь по кнопке: чужой таймаут не должен вешать
 * админку.
 */
interface check {

    /** Машинное имя, годится в ключ кеша и в data-атрибут. */
    public function name(): string;

    /** Заголовок для человека. */
    public function title(): string;

    /** Можно ли считать на каждой странице. */
    public function is_cheap(): bool;

    public function run(): check_result;
}
