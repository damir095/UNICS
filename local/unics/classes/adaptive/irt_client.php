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

    /** @param array $responses список ['discrimination'=>float,'difficulty'=>float,'correct'=>0|1] */
    public static function estimate(array $responses, ?float $prior_theta, ?float $prior_se): ?array {
        $payload = ['model' => '2pl', 'responses' => array_values($responses), 'prior' => null];
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
        $data = self::post('/calibrate', ['model' => '2pl', 'responses' => array_values($responses)]);
        if (!$data || !isset($data['items']) || !is_array($data['items'])) {
            return null;
        }
        return $data['items'];
    }

    /**
     * @param array $skills список ['element_id'=>int,'depth'=>int,'score'=>float,'band'=>int,
     *                       'theta'=>?float,'theta_se'=>?float,'attempts_n'=>int]
     * @return array|null список рекомендаций ['element_id'=>int,'kind'=>string,'priority'=>float,
     *                   'reason_code'=>string] или null при сбое
     */
    public static function recommend(array $skills, int $top_n): ?array {
        $data = self::post('/recommend',
            ['model' => 'content_v1', 'top_n' => $top_n, 'skills' => array_values($skills)]);
        if (!$data || !isset($data['recommendations']) || !is_array($data['recommendations'])) {
            return null;
        }
        return $data['recommendations'];
    }

    /**
     * @param array $responses список ['a'=>float,'b'=>float,'correct'=>0|1] (выданные)
     * @param array $candidates список ['item_ref'=>int,'a'=>float,'b'=>float] (не выданные)
     * @return array|null ['theta'=>float,'se'=>float,'next_item_ref'=>?int,'stop'=>bool,'reason'=>string]
     */
    public static function cat_next(array $responses, array $candidates, float $se_threshold,
            int $min_items, int $max_items, ?float $prior_theta = null, ?float $prior_se = null): ?array {
        $payload = [
            'responses' => array_values($responses),
            'candidates' => array_values($candidates),
            'se_threshold' => $se_threshold,
            'min_items' => $min_items,
            'max_items' => $max_items,
            'prior' => null,
        ];
        if ($prior_theta !== null && $prior_se !== null) {
            $payload['prior'] = ['theta' => $prior_theta, 'se' => $prior_se];
        }
        $data = self::post('/cat/next', $payload);
        if (!$data || !array_key_exists('stop', $data) || !isset($data['theta'], $data['se'])) {
            return null;
        }
        return [
            'theta' => (float)$data['theta'],
            'se' => (float)$data['se'],
            'next_item_ref' => isset($data['next_item_ref']) ? (int)$data['next_item_ref'] : null,
            'stop' => (bool)$data['stop'],
            'reason' => (string)($data['reason'] ?? ''),
        ];
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
