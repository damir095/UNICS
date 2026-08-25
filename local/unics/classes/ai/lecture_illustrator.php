<?php
namespace local_unics\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Иллюстрации учебного текста УМК ([[ai-lecture-images-design]]).
 *
 * Класс намеренно чистый: ни сети, ни БД. Сама генерация картинки живет в
 * ai_generator::generate_image(), сюда приходят только имена уже сохраненных файлов.
 * Так разбор текста, промт и вставка разметки покрываются юнит-тестами целиком, а
 * воркер остается тонким.
 *
 * Визуальная опора на КАЖДЫЙ смысловой блок - это дипломный тезис про ОВЗ, а не
 * украшение: при ЗПР и РАС картинка держит понимание текста.
 *
 * @package local_unics
 */
class lecture_illustrator {

    /**
     * Потолок картинок на одну лекцию. Живет ВНУТРИ класса и применяется в
     * split_sections(): вынеси его к вызывающему - и проверять потолок пришлось бы
     * тестом воркера, который ходит в сеть.
     */
    public const MAX_IMAGES = 4;

    /**
     * Заголовок раздела после output_style::shift_headings(). Общий для разбора и вставки.
     *
     * Уровней от четырех до шести намеренно: сдвиг превращает модельные # / ## / ### в
     * #### / ##### / ######, и модель размечает не плоский список, а иерархию. Ловить
     * только «####» значило бы иллюстрировать один заголовок документа.
     */
    private const HEADING_RE = '/^#{4,6}[ \t]*(.+?)[ \t]*$/mu';

    /** Сколько символов раздела уходит в промт картинки. */
    private const LEAD_LEN = 200;

    /**
     * Указания по рисованию для типов ОВЗ. Ключи те же, что у ovz_type_ids в
     * build_criteria(). Карта ОТДЕЛЬНАЯ от текстовой $ovz_instructions: те написаны про
     * абзацы и предложения и в директиве рисования бессмысленны. Типы 2, 3 и 6
     * (слабослышащий, НОДА, иное) добавки не получают сознательно - специфики
     * изображения у них нет.
     */
    private const VISUAL_INSTRUCTIONS = [
        1 => 'Крупные контрастные объекты, никаких мелких деталей.',
        4 => 'Один узнаваемый объект в центре, простой однотонный фон, без мелких деталей.',
        5 => 'Буквальное изображение без метафор и иносказаний, спокойные неяркие цвета.',
    ];

    /**
     * Разделы текста, под которые нужны картинки.
     *
     * @param string $md учебный текст в markdown
     * @param string $topic тема УМК - нужна для запасного пути без заголовков
     * @param int $max потолок
     * @return array<int, array{heading: string, lead: string}>
     */
    /**
     * Заголовки разделов со смещениями, БЕЗ тех, что лежат внутри блоков кода.
     *
     * Регулярка по всему тексту считала заголовком и строку «#### считаем сумму» внутри примера
     * на Python: раздел появлялся из комментария к коду и получал иллюстрацию
     * ([[code-fence-and-math-design]]). Тот же дефект в shift_headings() чинили еще в августе -
     * тем же построчным обходом.
     *
     * @return array<int, array{heading: string, hstart: int, bstart: int}>
     */
    private static function headings_of(string $md): array {
        $outside = output_style::lines_outside_code($md);
        $found = [];
        $offset = 0;
        foreach (preg_split('/\R/u', $md) ?: [] as $i => $line) {
            $len = strlen($line);
            if (($outside[$i] ?? true) && preg_match(self::HEADING_RE, $line, $m)) {
                $found[] = [
                    'heading' => self::clean_heading($m[1]),
                    'hstart'  => $offset,
                    'bstart'  => $offset + $len,
                ];
            }
            // +1 за перенос строки. Смещения нужны точные: по ним режется тело раздела,
            // и ошибка на единицу уводит в промт картинки обрезок соседнего.
            $offset += $len + 1;
        }
        return $found;
    }

    public static function split_sections(string $md, string $topic,
                                          int $max = self::MAX_IMAGES): array {
        if ($max < 1) {
            return [];
        }

        $found = self::headings_of($md);

        // Модель не разметила разделы - одна вводная картинка по теме УМК.
        if (empty($found)) {
            $lead = self::lead($md);
            return $lead === '' ? [] : [['heading' => $topic, 'lead' => $lead]];
        }

        $out = [];
        foreach ($found as $i => $sec) {
            if (count($out) >= $max) {
                break;
            }
            $end  = isset($found[$i + 1]) ? $found[$i + 1]['hstart'] : strlen($md);
            $body = substr($md, $sec['bstart'], $end - $sec['bstart']);
            $out[] = ['heading' => $sec['heading'], 'lead' => self::lead($body)];
        }
        return $out;
    }

    /**
     * Промт рисования. Указания педагога (extra_prompt) сюда НЕ передаются
     * ([[ai-lecture-images-design]], раздел 4.2) - поле пишется словами про текст.
     *
     * @param array $criteria результат ai_generator::build_criteria()
     */
    public static function build_image_prompt(array $criteria, string $topic,
                                              string $heading, string $lead): string {
        $prompt = 'Нарисуй образовательную иллюстрацию для школьного урока по теме «'
            . $topic . '», к разделу «' . $heading . '».';

        $lead = trim($lead);
        if ($lead !== '') {
            $prompt .= ' Содержание раздела: ' . $lead;
        }

        $prompt .= ' Стиль: чистый, минималистичный, яркий.'
            . ' Без подписей и текста на изображении.';

        foreach ((array)($criteria['ovz_type_ids'] ?? []) as $type) {
            if (isset(self::VISUAL_INSTRUCTIONS[(int)$type])) {
                $prompt .= ' ' . self::VISUAL_INSTRUCTIONS[(int)$type];
            }
        }

        return $prompt;
    }

    /**
     * Вставить разметку картинок в текст.
     *
     * @param array $sections результат split_sections() - оттуда берется alt
     * @param array<int, string> $filenames индекс раздела => имя файла; отсутствующий
     *        ключ означает, что картинка не создалась
     */
    public static function insert_images(string $md, array $sections, array $filenames): string {
        if (empty($filenames) || empty($sections)) {
            return $md;
        }

        // Заголовков нет - единственная картинка идет в начало текста.
        if (!self::headings_of($md)) {
            return isset($filenames[0])
                ? self::img_tag($filenames[0], $sections[0]['heading']) . "\n\n" . $md
                : $md;
        }

        // Построчно и в обход блоков кода - как и разбивка на разделы. Прежний
        // preg_replace_callback по всему тексту врезал бы картинку после строки «#### считаем
        // сумму» внутри примера на Python и сбил бы соответствие картинок разделам
        // ([[code-fence-and-math-design]]).
        $outside = output_style::lines_outside_code($md);
        $lines = preg_split('/\R/u', $md) ?: [];
        $idx = -1;
        $out = [];
        foreach ($lines as $i => $line) {
            $out[] = $line;
            if (!($outside[$i] ?? true) || !preg_match(self::HEADING_RE, $line, $m)) {
                continue;
            }
            $idx++;
            if (isset($filenames[$idx])) {
                $out[] = '';
                $out[] = self::img_tag($filenames[$idx], self::clean_heading($m[1]));
            }
        }
        return implode("\n", $out);
    }

    /**
     * Текст заголовка без разметки: остаток решеток от более глубокого уровня и
     * markdown-выделение. И то и другое утекало в alt картинки при живой генерации.
     */
    private static function clean_heading(string $raw): string {
        return trim(preg_replace('/^[#*_\s]+|[#*_\s]+$/u', '', $raw));
    }

    /** Разметка одной картинки. Класс - хук для стиля в _unics-pages.scss. */
    private static function img_tag(string $filename, string $alt): string {
        return '<p class="unics-lecture-img"><img src="@@PLUGINFILE@@/' . $filename
            . '" alt="' . s($alt) . '"></p>';
    }

    /** Начало раздела для промта: пробелы схлопнуты, обрезка по границе слова. */
    private static function lead(string $body): string {
        $flat = trim((string)preg_replace('/\s+/u', ' ', $body));
        if ($flat === '' || \core_text::strlen($flat) <= self::LEAD_LEN) {
            return $flat;
        }
        $cut = \core_text::substr($flat, 0, self::LEAD_LEN);
        $sp  = \core_text::strrpos($cut, ' ');
        return $sp > 0 ? \core_text::substr($cut, 0, $sp) : $cut;
    }
}
