<?php
namespace local_unics\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Общий парсер слайдов УМК-презентации (разметка course_builder: div.unics-slide).
 *
 * Используется PDF-экспортом (pages/umk_export.php) и PPTX-экспортом
 * (pages/umk_export_pptx.php). Дизайн: [[umk-pptx-export-design]] в LLM-вики.
 *
 * @package local_unics
 */
class slide_parser {

    /** Значок динамика, который course_builder ставит в заголовок при озвучке. */
    private const AUDIO_ICON = "\u{1F50A}";

    /**
     * Является ли контент mod_page УМК-презентацией.
     */
    public static function is_presentation(string $html): bool {
        return strpos($html, 'unics-slide') !== false;
    }

    /**
     * Разбор контента презентации на слайды.
     *
     * @param string $html контент mod_page
     * @return array<int, array{title:string, content_html:string, kp_html:string,
     *               image:?string, image_mime:?string}>
     */
    public static function parse(string $html): array {
        if (!self::is_presentation($html)) {
            return [];
        }

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $xp = new \DOMXPath($doc);

        $slides = [];
        $nodes = $xp->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' unics-slide ')]");
        foreach ($nodes as $node) {
            $titlenode = $xp->query(".//*[contains(@class,'unics-slide-title')]", $node)->item(0);
            $title = $titlenode ? trim($titlenode->textContent) : '';
            $title = trim(str_replace(self::AUDIO_ICON, '', $title));

            $cnode = $xp->query(".//*[contains(@class,'unics-slide-content')]", $node)->item(0);
            $content = $cnode ? self::inner_html($cnode) : '';

            $kpnode = $xp->query(".//*[contains(@class,'unics-kp')]", $node)->item(0);
            $kp = $kpnode ? self::inner_html($kpnode) : '';

            [$image, $mime] = self::extract_image($xp, $node);

            $slides[] = [
                'title'        => $title,
                'content_html' => $content,
                'kp_html'      => $kp,
                'image'        => $image,
                'image_mime'   => $mime,
            ];
        }
        return $slides;
    }

    /**
     * Плоский текст контента слайда: br -> перенос строки, теги долой, entity-декод.
     *
     * @return string[] непустые строки
     */
    public static function text_lines(string $content_html): array {
        $text = preg_replace('~<br\s*/?\s*>~i', "\n", $content_html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_map('trim', explode("\n", $text));
        return array_values(array_filter($lines, static fn($l) => $l !== ''));
    }

    /**
     * Тексты пунктов блока «Ключевые понятия» (li из kp_html).
     *
     * @return string[]
     */
    public static function kp_items(string $kp_html): array {
        if (trim($kp_html) === '') {
            return [];
        }
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $kp_html . '</div>');
        libxml_clear_errors();
        $items = [];
        foreach ($doc->getElementsByTagName('li') as $li) {
            $text = trim($li->textContent);
            if ($text !== '') {
                $items[] = $text;
            }
        }
        return $items;
    }

    /**
     * innerHTML узла (сериализация детей).
     */
    private static function inner_html(\DOMNode $node): string {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }
        return $html;
    }

    /**
     * Бинарник и mime картинки слайда из data:-src (.unics-slide-img img), если есть.
     *
     * @return array{0:?string, 1:?string}
     */
    private static function extract_image(\DOMXPath $xp, \DOMNode $slide): array {
        $img = $xp->query(".//*[contains(@class,'unics-slide-img')]//img", $slide)->item(0);
        if (!$img instanceof \DOMElement) {
            return [null, null];
        }
        $src = (string)$img->getAttribute('src');
        if (!preg_match('~^data:(image/[\w.+-]+);base64,(.+)$~s', $src, $m)) {
            return [null, null];
        }
        $bin = base64_decode($m[2], true);
        if ($bin === false || $bin === '') {
            return [null, null];
        }
        return [$bin, $m[1]];
    }
}
