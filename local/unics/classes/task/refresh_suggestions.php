<?php
namespace local_unics\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Отложенный пересчет адаптивных предложений ([[refresh-suggestions-task-design]]).
 *
 * Отложено намеренно. Рекомендатель и рассылка педагогам работали синхронно в том же запросе, в
 * котором ребенок отвечал на последнее задание проверки или отправлял тест: при включенном
 * ML-рекомендателе это лишние до 5 секунд ожидания (таймаут irt_client) плюс по письму каждому
 * привязанному педагогу. Ребенку эта работа не нужна - он ждет только свой результат, а
 * предложение педагогу читают не в ту же секунду.
 *
 * Запись владения при этом осталась синхронной: ее ребенок видит сразу на экране итога.
 *
 * Ретрай оставлен по умолчанию: работа идемпотентна. suggestion_service::create() молча выходит,
 * если открытое предложение той же пары уже есть, и уведомление уходит только при реальной
 * вставке - повтор после сбоя не добавит педагогу вторую карточку.
 *
 * @package local_unics
 */
class refresh_suggestions extends \core\task\adhoc_task {

    public function execute(): void {
        $data = (object)((array)$this->get_custom_data());
        $sid = (int)($data->student_id ?? 0);
        if ($sid <= 0) {
            // Битые данные не валят прогон: cron выполняет эту задачу вперемешку с чужими.
            mtrace('local_unics: refresh_suggestions без student_id, пропуск');
            return;
        }

        \local_unics\learning\mastery_manager::regenerate_suggestions($sid);

        // Вторая половина: глобальный гейт уровня. on_attempt() всегда звал его следом за
        // предложениями, и без него ребенок, который проверяется только через CAT, предложения
        // сменить общий уровень сложности не увидит никогда.
        try {
            \local_unics\learning\adaptive_engine::gate_level_change($sid);
        } catch (\Throwable $e) {
            debugging('local_unics: глобальный rollup не удался: ' . $e->getMessage(),
                DEBUG_DEVELOPER);
        }
    }
}
