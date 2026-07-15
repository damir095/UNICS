<?php
namespace local_unics\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Генератор PPTX из слайдов УМК-презентации (формат slide_parser::parse()).
 *
 * Свой минимальный OOXML-пакет (zip + XML) без внешних зависимостей:
 * титульный слайд (логотип + название + курс) и рабочие слайды 16:9 в бренд-стиле
 * (коралловая плашка заголовка, текст, картинка справа, блок «Ключевые понятия»).
 * Дизайн: [[umk-pptx-export-design]] в LLM-вики.
 *
 * @package local_unics
 */
class pptx_writer {

    /** Размер слайда 16:9 в EMU (1 см = 360000 EMU). */
    private const SLIDE_CX = 12192000;
    private const SLIDE_CY = 6858000;

    private const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    private const NS_P = 'http://schemas.openxmlformats.org/presentationml/2006/main';
    private const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Палитра бренда (без #, как требует srgbClr). */
    private const C_PRIMARY = 'F26545';
    private const C_TEXT    = '1F2430';
    private const C_BODY    = '292F3B';
    private const C_MUTED   = '6B7280';
    private const C_KP_BG   = 'FEF3F0';

    /**
     * Собрать PPTX.
     *
     * @param string $prestitle название презентации (имя mod_page)
     * @param string $coursename имя курса (титул)
     * @param array $slides слайды в формате slide_parser::parse()
     * @return string бинарное содержимое файла .pptx
     */
    public static function build(string $prestitle, string $coursename, array $slides): string {
        $parts = [];
        $media = [];   // имя в ppt/media => бинарник
        $slidexml = []; // номер => [xml, rels]

        // Титульный слайд (номер 1).
        $logo = self::logo_png();
        if ($logo !== null) {
            $media['logo.png'] = $logo;
        }
        $slidexml[1] = self::title_slide($prestitle, $coursename, $logo !== null);

        // Рабочие слайды.
        $imgno = 0;
        foreach (array_values($slides) as $i => $slide) {
            $imgname = null;
            if (!empty($slide['image'])) {
                $imgno++;
                $imgname = 'image' . $imgno . '.' . self::image_ext($slide['image_mime'] ?? '');
                $media[$imgname] = $slide['image'];
            }
            $slidexml[$i + 2] = self::content_slide($slide, $i + 2, count($slides) + 1, $imgname);
        }

        $total = count($slidexml);

        // Части пакета.
        $parts['[Content_Types].xml'] = self::content_types($total, array_keys($media));
        $parts['_rels/.rels'] = self::root_rels();
        $parts['docProps/core.xml'] = self::core_props($prestitle);
        $parts['docProps/app.xml'] = self::app_props();
        $parts['ppt/presentation.xml'] = self::presentation($total);
        $parts['ppt/_rels/presentation.xml.rels'] = self::presentation_rels($total);
        $parts['ppt/theme/theme1.xml'] = self::theme();
        $parts['ppt/slideMasters/slideMaster1.xml'] = self::slide_master();
        $parts['ppt/slideMasters/_rels/slideMaster1.xml.rels'] = self::slide_master_rels();
        $parts['ppt/slideLayouts/slideLayout1.xml'] = self::slide_layout();
        $parts['ppt/slideLayouts/_rels/slideLayout1.xml.rels'] = self::slide_layout_rels();

        foreach ($slidexml as $n => [$xml, $rels]) {
            $parts["ppt/slides/slide{$n}.xml"] = $xml;
            $parts["ppt/slides/_rels/slide{$n}.xml.rels"] = $rels;
        }
        foreach ($media as $name => $bin) {
            $parts["ppt/media/{$name}"] = $bin;
        }

        return self::zip($parts);
    }

    // -------------------------------------------------------------------------
    // Слайды
    // -------------------------------------------------------------------------

    /** @return array{0:string,1:string} [xml слайда, xml rels] */
    private static function title_slide(string $prestitle, string $coursename, bool $withlogo): array {
        $shapes = '';
        $rels = [self::rel(1, self::REL . '/slideLayout', '../slideLayouts/slideLayout1.xml')];

        if ($withlogo) {
            // Логотип 600x192 -> 2743200 x 877824 EMU, левый верх.
            $shapes .= self::pic(10, 'rId2', 457200, 457200, 2743200, 877824);
            $rels[] = self::rel(2, self::REL . '/image', '../media/logo.png');
        }

        // Название презентации.
        $shapes .= self::textbox(2, 457200, 2400300, 11277600, 1200150,
            [self::para(self::run($prestitle, 4000, true, self::C_TEXT))], null, 'b');
        // Коралловая полоса-акцент.
        $shapes .= self::rect(3, 457200, 3721100, 2743200, 91440, self::C_PRIMARY);
        // Имя курса.
        $shapes .= self::textbox(4, 457200, 3980180, 11277600, 640080,
            [self::para(self::run($coursename, 2000, false, self::C_MUTED))]);

        return [self::slide_xml($shapes), self::rels_xml($rels)];
    }

    /** @return array{0:string,1:string} */
    private static function content_slide(array $slide, int $number, int $total, ?string $imgname): array {
        $rels = [self::rel(1, self::REL . '/slideLayout', '../slideLayouts/slideLayout1.xml')];
        $shapes = '';

        // Плашка заголовка.
        $shapes .= self::rect(2, 0, 0, self::SLIDE_CX, 1143000, self::C_PRIMARY);
        $shapes .= self::textbox(3, 457200, 0, 11277600, 1143000,
            [self::para(self::run((string)$slide['title'], 2800, true, 'FFFFFF'))], null, 'ctr');

        $haskp = trim((string)$slide['kp_html']) !== '';
        $hasimg = $imgname !== null;

        // Тело: абзацы текста.
        $bodytop = 1371600;
        $bodyh = $haskp ? 3429000 : 4800600;
        $bodyw = $hasimg ? 6400800 : 11277600;
        $paras = [];
        foreach (slide_parser::text_lines((string)$slide['content_html']) as $line) {
            $paras[] = self::para(self::run($line, 1600, false, self::C_BODY), 600);
        }
        if (empty($paras)) {
            $paras[] = self::para(self::run('', 1600, false, self::C_BODY));
        }
        $shapes .= self::textbox(4, 457200, $bodytop, $bodyw, $bodyh, $paras, null, 't', true);

        // Картинка справа от текста.
        if ($hasimg) {
            $rels[] = self::rel(2, self::REL . '/image', '../media/' . $imgname);
            [$px, $py, $pw, $ph] = self::fit_image($slide['image'], 7086600, $bodytop, 4648200, $bodyh);
            $shapes .= self::pic(5, 'rId2', $px, $py, $pw, $ph);
        }

        // Блок «Ключевые понятия».
        if ($haskp) {
            $kptop = 4972050;
            $kpparas = [self::para(self::run('Ключевые понятия:', 1400, true, self::C_BODY))];
            foreach (slide_parser::kp_items((string)$slide['kp_html']) as $item) {
                $kpparas[] = self::para(self::run($item, 1400, false, self::C_BODY), 0, true);
            }
            $shapes .= self::textbox(6, 457200, $kptop, 11277600, 1543050, $kpparas,
                self::C_KP_BG, 't', true, self::C_PRIMARY);
        }

        // Номер слайда (без титула в счетчике не хитрим - просто номер/всего).
        $shapes .= self::textbox(7, 11049000, 6446520, 800100, 320040,
            [self::para(self::run($number . ' / ' . $total, 1100, false, self::C_MUTED))]);

        return [self::slide_xml($shapes), self::rels_xml($rels)];
    }

    /** Вписать картинку в бокс с сохранением пропорций (если размер известен). */
    private static function fit_image(string $bin, int $bx, int $by, int $bw, int $bh): array {
        $size = @getimagesizefromstring($bin);
        if (!is_array($size) || empty($size[0]) || empty($size[1])) {
            return [$bx, $by, $bw, $bh];
        }
        $scale = min($bw / $size[0], $bh / $size[1]);
        $w = (int)($size[0] * $scale);
        $h = (int)($size[1] * $scale);
        return [$bx + (int)(($bw - $w) / 2), $by + (int)(($bh - $h) / 2), $w, $h];
    }

    /** PNG-логотип для титула (нет файла - титул без логотипа). */
    private static function logo_png(): ?string {
        global $CFG;
        $path = $CFG->dirroot . '/local/unics/pix/unics-logo.png';
        if (!is_readable($path)) {
            return null;
        }
        $bin = file_get_contents($path);
        return ($bin === false || $bin === '') ? null : $bin;
    }

    // -------------------------------------------------------------------------
    // XML-примитивы фигур
    // -------------------------------------------------------------------------

    private static function esc(string $s): string {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Текстовый run с размером (сотые пункта), жирностью и цветом. */
    private static function run(string $text, int $sz, bool $bold, string $color): string {
        $b = $bold ? ' b="1"' : '';
        return '<a:r><a:rPr lang="ru-RU" sz="' . $sz . '"' . $b . ' dirty="0">'
            . '<a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill>'
            . '<a:latin typeface="Calibri"/><a:cs typeface="Calibri"/></a:rPr>'
            . '<a:t>' . self::esc($text) . '</a:t></a:r>';
    }

    /** Абзац; $spcaft - отступ после (сотые пункта), $bullet - маркер-точка. */
    private static function para(string $runs, int $spcaft = 0, bool $bullet = false): string {
        $ppr = '';
        if ($spcaft > 0 || $bullet) {
            $ppr = '<a:pPr' . ($bullet ? ' marL="285750" indent="-285750"' : '') . '>'
                . ($spcaft > 0 ? '<a:spcAft><a:spcPts val="' . $spcaft . '"/></a:spcAft>' : '')
                . ($bullet ? '<a:buChar char="' . "\u{2022}" . '"/>' : '')
                . '</a:pPr>';
        }
        return '<a:p>' . $ppr . $runs . '</a:p>';
    }

    /**
     * Текст-бокс. $fill/$line - цвета заливки/рамки (null = без), $anchor: t|ctr|b,
     * $autofit - normAutofit (ужимать текст, не обрезая).
     */
    private static function textbox(int $id, int $x, int $y, int $cx, int $cy, array $paras,
            ?string $fill = null, string $anchor = 't', bool $autofit = false, ?string $line = null): string {
        $fillxml = $fill !== null ? '<a:solidFill><a:srgbClr val="' . $fill . '"/></a:solidFill>' : '';
        $linexml = $line !== null
            ? '<a:ln w="19050"><a:solidFill><a:srgbClr val="' . $line . '"/></a:solidFill></a:ln>' : '';
        return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="TextBox ' . $id . '"/>'
            . '<p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>' . $fillxml . $linexml . '</p:spPr>'
            . '<p:txBody><a:bodyPr wrap="square" lIns="91440" tIns="45720" rIns="91440" bIns="45720" anchor="'
            . $anchor . '">' . ($autofit ? '<a:normAutofit/>' : '') . '</a:bodyPr><a:lstStyle/>'
            . implode('', $paras) . '</p:txBody></p:sp>';
    }

    /** Прямоугольник-заливка (плашки, полосы). */
    private static function rect(int $id, int $x, int $y, int $cx, int $cy, string $fill): string {
        return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="Rect ' . $id . '"/>'
            . '<p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
            . '<a:solidFill><a:srgbClr val="' . $fill . '"/></a:solidFill>'
            . '<a:ln><a:noFill/></a:ln></p:spPr>'
            . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p/></p:txBody></p:sp>';
    }

    /** Картинка по rId. */
    private static function pic(int $id, string $rid, int $x, int $y, int $cx, int $cy): string {
        return '<p:pic><p:nvPicPr><p:cNvPr id="' . $id . '" name="Image ' . $id . '"/>'
            . '<p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr><p:nvPr/></p:nvPicPr>'
            . '<p:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>'
            . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic>';
    }

    private static function slide_xml(string $shapes): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:sld xmlns:a="' . self::NS_A . '" xmlns:r="' . self::NS_R . '" xmlns:p="' . self::NS_P . '">'
            . '<p:cSld><p:spTree>'
            . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>'
            . '<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            . $shapes
            . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';
    }

    // -------------------------------------------------------------------------
    // Части пакета
    // -------------------------------------------------------------------------

    private static function content_types(int $slides, array $medianames): string {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="jpeg" ContentType="image/jpeg"/>'
            . '<Default Extension="png" ContentType="image/png"/>'
            . '<Default Extension="gif" ContentType="image/gif"/>'
            . '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
            . '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
            . '<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
            . '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        for ($n = 1; $n <= $slides; $n++) {
            $xml .= '<Override PartName="/ppt/slides/slide' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
        }
        return $xml . '</Types>';
    }

    private static function root_rels(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . self::REL . '/officeDocument" Target="ppt/presentation.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="' . self::REL . '/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private static function core_props(string $title): string {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . self::esc($title) . '</dc:title>'
            . '<dc:creator>УНИКС</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private static function app_props(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
            . '<Application>УНИКС (Moodle local_unics)</Application>'
            . '</Properties>';
    }

    private static function presentation(int $slides): string {
        $ids = '';
        for ($n = 1; $n <= $slides; $n++) {
            $ids .= '<p:sldId id="' . (255 + $n) . '" r:id="rId' . ($n + 1) . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:presentation xmlns:a="' . self::NS_A . '" xmlns:r="' . self::NS_R . '" xmlns:p="' . self::NS_P . '">'
            . '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
            . '<p:sldIdLst>' . $ids . '</p:sldIdLst>'
            . '<p:sldSz cx="' . self::SLIDE_CX . '" cy="' . self::SLIDE_CY . '"/>'
            . '<p:notesSz cx="6858000" cy="9144000"/>'
            . '</p:presentation>';
    }

    private static function presentation_rels(int $slides): string {
        $rels = [self::rel(1, self::REL . '/slideMaster', 'slideMasters/slideMaster1.xml')];
        for ($n = 1; $n <= $slides; $n++) {
            $rels[] = self::rel($n + 1, self::REL . '/slide', 'slides/slide' . $n . '.xml');
        }
        return self::rels_xml($rels);
    }

    private static function slide_master(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:sldMaster xmlns:a="' . self::NS_A . '" xmlns:r="' . self::NS_R . '" xmlns:p="' . self::NS_P . '">'
            . '<p:cSld><p:bg><p:bgPr><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill><a:effectLst/></p:bgPr></p:bg>'
            . '<p:spTree>'
            . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>'
            . '<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            . '</p:spTree></p:cSld>'
            . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2"'
            . ' accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
            . '<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>'
            . '<p:txStyles><p:titleStyle/><p:bodyStyle/><p:otherStyle/></p:txStyles>'
            . '</p:sldMaster>';
    }

    private static function slide_master_rels(): string {
        return self::rels_xml([
            self::rel(1, self::REL . '/slideLayout', '../slideLayouts/slideLayout1.xml'),
            self::rel(2, self::REL . '/theme', '../theme/theme1.xml'),
        ]);
    }

    private static function slide_layout(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:sldLayout xmlns:a="' . self::NS_A . '" xmlns:r="' . self::NS_R . '" xmlns:p="' . self::NS_P . '" type="blank" preserve="1">'
            . '<p:cSld name="Blank"><p:spTree>'
            . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>'
            . '<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>';
    }

    private static function slide_layout_rels(): string {
        return self::rels_xml([
            self::rel(1, self::REL . '/slideMaster', '../slideMasters/slideMaster1.xml'),
        ]);
    }

    /** Минимальная тема (обязательна для master; палитра стандартного Office). */
    private static function theme(): string {
        $fmt = '<a:fillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
            . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
            . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:fillStyleLst>'
            . '<a:lnStyleLst>'
            . '<a:ln w="6350"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>'
            . '<a:ln w="12700"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>'
            . '<a:ln w="19050"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>'
            . '</a:lnStyleLst>'
            . '<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle>'
            . '<a:effectStyle><a:effectLst/></a:effectStyle>'
            . '<a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>'
            . '<a:bgFillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
            . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
            . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:bgFillStyleLst>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<a:theme xmlns:a="' . self::NS_A . '" name="UNICS">'
            . '<a:themeElements>'
            . '<a:clrScheme name="UNICS">'
            . '<a:dk1><a:srgbClr val="1F2430"/></a:dk1><a:lt1><a:srgbClr val="FFFFFF"/></a:lt1>'
            . '<a:dk2><a:srgbClr val="292F3B"/></a:dk2><a:lt2><a:srgbClr val="F5F6F9"/></a:lt2>'
            . '<a:accent1><a:srgbClr val="F26545"/></a:accent1><a:accent2><a:srgbClr val="C44A2F"/></a:accent2>'
            . '<a:accent3><a:srgbClr val="A93D24"/></a:accent3><a:accent4><a:srgbClr val="1E7E34"/></a:accent4>'
            . '<a:accent5><a:srgbClr val="1565C0"/></a:accent5><a:accent6><a:srgbClr val="B25E09"/></a:accent6>'
            . '<a:hlink><a:srgbClr val="C44A2F"/></a:hlink><a:folHlink><a:srgbClr val="A93D24"/></a:folHlink>'
            . '</a:clrScheme>'
            . '<a:fontScheme name="UNICS">'
            . '<a:majorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont>'
            . '<a:minorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont>'
            . '</a:fontScheme>'
            . '<a:fmtScheme name="UNICS">' . $fmt . '</a:fmtScheme>'
            . '</a:themeElements>'
            . '</a:theme>';
    }

    // -------------------------------------------------------------------------
    // Служебное
    // -------------------------------------------------------------------------

    private static function rel(int $id, string $type, string $target): string {
        return '<Relationship Id="rId' . $id . '" Type="' . $type . '" Target="' . $target . '"/>';
    }

    private static function rels_xml(array $rels): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . implode('', $rels) . '</Relationships>';
    }

    private static function image_ext(string $mime): string {
        return ['image/jpeg' => 'jpeg', 'image/png' => 'png', 'image/gif' => 'gif'][$mime] ?? 'jpeg';
    }

    /** Собрать zip из карты имя -> содержимое (ZipArchive пишет только в файл). */
    private static function zip(array $parts): string {
        $tmp = tempnam(sys_get_temp_dir(), 'unicspptx');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'pptx: zip open failed');
        }
        foreach ($parts as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bin = file_get_contents($tmp);
        @unlink($tmp);
        if ($bin === false) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'pptx: zip read failed');
        }
        return $bin;
    }
}
