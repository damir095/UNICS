<?php
namespace local_unics\adaptive;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * HTTP-клиент к Python-микросервису IRT. Изолирует сеть от логики оценщика. Любая
 * ошибка/таймаут/недоступность -> null (вызывающий делает graceful fallback). По сети
 * уходят ТОЛЬКО числовые признаки (обезличивание на границе API).
 */
class irt_client {

    /** Таймаут запроса, сек. */
    const TIMEOUT = 5;

    private static function base_url(): string {
        $url = trim((string)get_config('local_unics', 'irt_service_url'));
        return $url !== '' ? rtrim($url, '/') : 'http://127.0.0.1:8000';
    }

    private static function headers(): array {
        $headers = ['Content-Type: application/json'];
        $token = (string)get_config('local_unics', 'irt_service_token');
        if ($token !== '') {
            $headers[] = 'X-UNICS-Token: ' . $token;
        }
        return $headers;
    }

    private static function post(string $path, array $payload): ?array {
        try {
            // Доверенный внутренний вызов по админ-настройке URL - обходим SSRF-проверку портов Moodle (сервис на 8000).
            $curl = new \curl(['ignoresecurity' => true]);
            $curl->setHeader(self::headers());
            $resp = $curl->post(self::base_url() . $path, json_encode($payload),
                ['CURLOPT_TIMEOUT' => self::TIMEOUT, 'CURLOPT_CONNECTTIMEOUT' => self::TIMEOUT]);
            if ($curl->get_errno() || (int)($curl->info['http_code'] ?? 0) !== 200) {
                return null;
            }
            $data = json_decode($resp, true);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            debugging('local_unics irt_client: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /** @param array $responses список ['difficulty'=>float,'correct'=>0|1] */
    public static function estimate(array $responses, ?float $prior_theta, ?float $prior_se): ?array {
        $payload = ['model' => 'rasch', 'responses' => array_values($responses), 'prior' => null];
        if ($prior_theta !== null && $prior_se !== null) {
            $payload['prior'] = ['theta' => $prior_theta, 'se' => $prior_se];
        }
        $data = self::post('/estimate', $payload);
        if (!$data || !isset($data['theta'], $data['se'])) {
            return null;
        }
        return ['theta' => (float)$data['theta'], 'se' => (float)$data['se']];
    }

    /** @param array $responses список ['student_ref'=>int,'item_ref'=>int,'correct'=>0|1] */
    public static function calibrate(array $responses): ?array {
        $data = self::post('/calibrate', ['model' => 'rasch', 'responses' => array_values($responses)]);
        if (!$data || !isset($data['items']) || !is_array($data['items'])) {
            return null;
        }
        return $data['items'];
    }

    public static function health(): bool {
        try {
            $curl = new \curl(['ignoresecurity' => true]);
            $curl->get(self::base_url() . '/health', [],
                ['CURLOPT_TIMEOUT' => self::TIMEOUT, 'CURLOPT_CONNECTTIMEOUT' => self::TIMEOUT]);
            return !$curl->get_errno() && (int)($curl->info['http_code'] ?? 0) === 200;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
