<?php
namespace local_unics\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Отказ внешнего сервиса ИИ, объясненный педагогу ([[ai-error-messages-design]]).
 *
 * Шесть throw в ai_generator передавали техническую фразу ПЕРВЫМ аргументом moodle_exception, а он
 * трактуется как идентификатор языковой строки. Наружу выходило
 * «error/GigaChat HTTP 402: Payment Required» - префикс от несостоявшегося поиска строки плюс
 * сообщение, из которого педагогу неясно, что делать.
 *
 * Сообщение состоит из двух частей: что делать (для педагога) и техническая деталь в скобках
 * (для разбора и для страницы здоровья, которая по ней узнает неоплаченный пакет).
 *
 * @package local_unics
 */
class ai_error {

    /** Сколько символов тела ответа оставляем: бывает страница HTML целиком. */
    private const DETAIL_MAX = 200;

    /**
     * Что делать педагогу при таком коде ответа.
     *
     * Отдельно 402: это устойчивое состояние оплаты аккаунта, а не сбой. Предлагать при нем
     * «повторите позже» - водить педагога по кругу, поэтому текст прямо говорит, что кодом это
     * не чинится ([[tts-honest-availability-design]]).
     */
    private static function advice(int $code, bool $auth): string {
        // 400 на этапе авторизации - это ключ, а не запрос. Живой заход 2026-08-25 с заведомо
        // негодным ключом получил от Сбера именно 400, и совет «запрос отклонен» не говорил
        // педагогу ничего. Тесты писались по учебнику (401/403), реальность оказалась другой.
        if ($code === 401 || $code === 403 || ($auth && $code === 400)) {
            return 'ключ доступа неверен или истек. Проверьте его: Администрирование - УНИКС - '
                . 'Настройки ИИ.';
        }
        if ($code === 402) {
            return 'у аккаунта нет оплаченного пакета. Это не сбой программы и не лечится '
                . 'кодом - нужна оплата у поставщика.';
        }
        if ($code === 429) {
            return 'слишком много запросов подряд. Повторите позже.';
        }
        // Код 0 означает, что ответа не было вовсе (curl_getinfo отдает ноль, когда не пришла
        // даже строка статуса). Говорить при нем «запрос отклонен» - утверждать, будто сервис
        // отверг то, чего не получал (найдено ревью 2026-08-25).
        if ($code >= 500 || $code <= 0) {
            return 'сбой на стороне поставщика. Повторите позже.';
        }
        return 'запрос отклонен.';
    }

    /**
     * Сообщение об отказе: понятная фраза плюс техническая деталь.
     *
     * @param string $service человекочитаемое имя сервиса («GigaChat», «SaluteSpeech»)
     * @param int $code код ответа HTTP
     * @param string $detail тело ответа или пояснение сервиса
     * @param bool $auth отказ пришел на этапе получения токена, а не на самом запросе
     */
    public static function message(string $service, int $code, string $detail = '',
                                   bool $auth = false): string {
        return self::compose($service, $auth ? ' (авторизация)' : '', 'HTTP ' . $code,
            $detail, self::advice($code, $auth));
    }

    /**
     * Сообщение о том, что до сервиса не удалось достучаться.
     *
     * Отдельный вход, потому что кода ответа тут нет вовсе: сеть легла, DNS не разрешился,
     * истек таймаут. Раньше такие throw оставались с голой строкой вида
     * «GigaChat cURL ошибка: Could not resolve host» - то есть ровно тем, что этот класс и
     * заведен убрать, а сетевой отказ как раз самый вероятный (найдено ревью 2026-08-25).
     */
    public static function transport(string $service, string $detail = '',
                                     bool $auth = false): string {
        return self::compose($service, $auth ? ' (авторизация)' : '', 'нет связи', $detail,
            'сервис недоступен: не удалось установить соединение. Проверьте интернет на сервере '
            . 'и повторите позже.');
    }

    /** Сборка сообщения: совет для педагога плюс техническая часть в скобках. */
    private static function compose(string $service, string $stage, string $kind, string $detail,
                                    string $advice): string {
        $detail = trim($detail);
        if (mb_strlen($detail) > self::DETAIL_MAX) {
            $detail = mb_substr($detail, 0, self::DETAIL_MAX) . '...';
        }

        // Код в технической части обязателен: страница здоровья узнает по нему неоплаченный
        // пакет, а без него увела бы администратора в ветку «проверьте ключ и интернет»
        // (такой промах уже был - тогда проверка смотрела на код, а в тексте лежало только
        // «Payment Required»).
        $tech = $service . $stage . ' ' . $kind;
        if ($detail !== '') {
            $tech .= ': ' . $detail;
        }

        return 'Сервис ' . $service . ' не ответил: ' . $advice . ' (' . $tech . ')';
    }

    /**
     * Готовое исключение с этим сообщением.
     *
     * Языковая строка СВОЯ (`aiservicefailed` = «{$a}»), а не ядровая generalexceptionmessage:
     * та подставляет текст в «Исключение - {$a}», и педагог видел бы жаргонный префикс - другой,
     * но такой же бесполезный, как прежний «error/» (найдено ревью 2026-08-25).
     */
    public static function exception(string $service, int $code, string $detail = '',
                                     bool $auth = false): \moodle_exception {
        return self::wrap(self::message($service, $code, $detail, $auth));
    }

    /** Готовое исключение о недоступности сервиса. */
    public static function transport_exception(string $service, string $detail = '',
                                               bool $auth = false): \moodle_exception {
        return self::wrap(self::transport($service, $detail, $auth));
    }

    private static function wrap(string $message): \moodle_exception {
        return new \moodle_exception('aiservicefailed', 'local_unics', '', $message);
    }
}
