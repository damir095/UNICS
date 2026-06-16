<?php
// Экспорт сгенерированного материала (mod_page) в PDF через встроенный TCPDF.
// Два вида страниц:
//   - текстовый материал (контент в Markdown) -> рендерим format_text -> HTML -> PDF;
//   - HTML5-видеопрезентация (слайды .unics-slide) -> разбираем слайды -> по странице на слайд.
// Доступ: любой, кто видит модуль (mod/page:view) - педагог/методист/админ и ученик
// (офлайн-чтение, печать крупным шрифтом - линия доступности).

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once($CFG->libdir . '/pdflib.php');

$cmid = required_param('cmid', PARAM_INT);

$cm     = get_coursemodule_from_id('page', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_login($course, false, $cm);           // уважает видимость/доступность модуля
$context = context_module::instance($cm->id);
require_capability('mod/page:view', $context);

$page = $DB->get_record('page', ['id' => $cm->instance], '*', MUST_EXIST);

$is_presentation = (strpos((string)$page->content, 'unics-slide') !== false);

// ---------------------------------------------------------------------------
// Хелперы разбора презентации
// ---------------------------------------------------------------------------
function local_unics_inner_html(DOMNode $node): string {
    $html = '';
    foreach ($node->childNodes as $child) {
        $html .= $node->ownerDocument->saveHTML($child);
    }
    return $html;
}

function local_unics_parse_slides(string $html): array {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $slides = [];
    $nodes = $xp->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' unics-slide ')]");
    foreach ($nodes as $node) {
        $titlenode = $xp->query(".//*[contains(@class,'unics-slide-title')]", $node)->item(0);
        $title = $titlenode ? trim($titlenode->textContent) : '';
        $title = trim(str_replace("\u{1F50A}", '', $title)); // убрать значок «динамик»

        $cnode = $xp->query(".//*[contains(@class,'unics-slide-content')]", $node)->item(0);
        $content = $cnode ? local_unics_inner_html($cnode) : '';

        $kpnode = $xp->query(".//*[contains(@class,'unics-kp')]", $node)->item(0);
        $kp = $kpnode ? local_unics_inner_html($kpnode) : '';

        $slides[] = ['title' => $title, 'content' => $content, 'kp' => $kp];
    }
    return $slides;
}

// ---------------------------------------------------------------------------
// PDF
// ---------------------------------------------------------------------------
$pdf = new pdf();
$pdf->SetCreator('УНИКС');
$pdf->SetTitle($page->name);
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 18);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetFont('freesans', '', 13); // Unicode-шрифт с поддержкой кириллицы

$coursename = format_string($course->fullname);
$head = '<div style="border-bottom:2px solid #F26545;padding-bottom:6px;margin-bottom:14px;">'
      . '<span style="color:#A93D24;font-size:10px;">УНИКС - ' . s($coursename) . '</span><br>'
      . '<span style="font-size:20px;font-weight:bold;color:#1F2933;">' . s($page->name) . '</span>'
      . '</div>';

if ($is_presentation) {
    $slides = local_unics_parse_slides((string)$page->content);
    if (empty($slides)) {
        $pdf->AddPage();
        $pdf->writeHTML($head . '<p>Слайды не найдены.</p>', true, false, true, false, '');
    } else {
        foreach ($slides as $i => $sl) {
            $pdf->AddPage();
            $html = ($i === 0 ? $head : '');
            $html .= '<h2 style="color:#C44A2F;font-size:17px;">Слайд ' . ($i + 1) . '. ' . s($sl['title']) . '</h2>';
            $html .= '<div style="font-size:13px;line-height:1.6;">' . $sl['content'] . '</div>';
            if (trim((string)$sl['kp']) !== '') {
                $html .= '<div style="font-size:12px;line-height:1.5;margin-top:10px;">' . $sl['kp'] . '</div>';
            }
            $pdf->writeHTML($html, true, false, true, false, '');
        }
    }
} else {
    $pdf->AddPage();
    $body = format_text((string)$page->content, $page->contentformat, ['context' => $context]);
    $pdf->writeHTML(
        $head . '<div style="font-size:13px;line-height:1.7;">' . $body . '</div>',
        true, false, true, false, ''
    );
}

$filename = clean_filename($page->name . '.pdf');
$pdf->Output($filename, 'D'); // скачивание
