<?php
require_once(__DIR__ . '/../../../config.php');

require_login();
global $USER, $DB;

// Только учащиеся имеют доступ к магазину
$student = $DB->get_record('unics_students', ['mdl_user_id' => $USER->id]);
if (!$student) {
    redirect(
        new moodle_url('/local/unics/pages/dashboard.php'),
        'Магазин доступен только учащимся.',
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

$ctx = context_system::instance();
$PAGE->set_context($ctx);
$PAGE->set_url(new moodle_url('/local/unics/pages/shop.php'));
$PAGE->set_title('Магазин УНИКС');
$PAGE->set_heading('Магазин наград');
$PAGE->set_pagelayout('standard');

require_once(__DIR__ . '/../classes/points_manager.php');
use local_unics\points_manager;

// ----------------------------------------------------------------
// Обработка покупки
// ----------------------------------------------------------------
$buy_item_id = optional_param('buy', 0, PARAM_INT);
$buy_msg     = '';
$buy_error   = '';

if ($buy_item_id > 0 && confirm_sesskey()) {
    $result = points_manager::purchase((int)$student->id, $buy_item_id);
    if ($result === true) {
        $item = $DB->get_record('unics_shop_items', ['id' => $buy_item_id]);
        $buy_msg = $item ? ('✅ Куплено: ' . $item->icon_emoji . ' ' . s($item->name)) : '✅ Покупка выполнена!';
        redirect(new moodle_url('/local/unics/pages/shop.php', ['bought' => 1]));
    } else {
        $buy_error = '❌ ' . ($result ?: 'Ошибка при покупке');
    }
}

if (optional_param('bought', 0, PARAM_INT)) {
    $buy_msg = '✅ Покупка выполнена! Товар добавлен в ваши достижения.';
}

// ----------------------------------------------------------------
// Данные
// ----------------------------------------------------------------
$balance  = points_manager::get_balance((int)$student->id);
$history  = points_manager::get_history((int)$student->id, 10);
$purchases = points_manager::get_purchases((int)$student->id);
$bought_ids = array_column($purchases, 'item_id');

$shop_items = $DB->get_records('unics_shop_items', ['is_active' => 1], 'sort_order, cost', '*');

// ----------------------------------------------------------------
// Вывод
// ----------------------------------------------------------------
echo $OUTPUT->header();

// Навигация
echo '<div class="mb-3">';
echo html_writer::link(
    new moodle_url('/local/unics/pages/dashboard.php'),
    '← Главная',
    ['class' => 'btn btn-outline-secondary btn-sm']
);
echo html_writer::link(
    new moodle_url('/local/unics/pages/achievements.php', ['student_id' => $student->id]),
    'Мои значки',
    ['class' => 'btn btn-outline-warning btn-sm ml-2']
);
echo '</div>';

// Сообщения
if ($buy_msg) {
    echo '<div class="alert alert-success">' . $buy_msg . '</div>';
}
if ($buy_error) {
    echo '<div class="alert alert-danger">' . $buy_error . '</div>';
}

// Баланс
echo '<div class="card mb-4" style="border-left: 4px solid #f0a500;">';
echo '<div class="card-body d-flex align-items-center">';
echo '<div style="font-size:3rem;line-height:1;margin-right:var(--unics-space-5);">🪙</div>';
echo '<div>';
echo '<div style="font-size:2rem;font-weight:700;color:#f0a500;">' . number_format($balance) . '</div>';
echo '<div class="text-muted">баллов на балансе</div>';
echo '</div>';
echo '</div>';
echo '</div>';

// Как зарабатывать баллы
echo '<div class="card mb-4 bg-light">';
echo '<div class="card-body py-2">';
echo '<strong>Как зарабатывать баллы:</strong> ';
echo '<span class="badge badge-secondary mr-1">+20 — новый УМК</span>';
echo '<span class="badge badge-secondary mr-1">+10 — тест сдан</span>';
echo '<span class="badge badge-secondary mr-1">+50 — значок</span>';
echo '<span class="badge badge-secondary">+100 — повышение уровня</span>';
echo '</div>';
echo '</div>';

// Товары магазина
echo '<h5 class="mb-3">🛍 Доступные товары</h5>';

if (empty($shop_items)) {
    echo '<p class="text-muted">В магазине пока нет товаров.</p>';
} else {
    echo '<div class="row">';
    foreach ($shop_items as $item) {
        $already_bought = in_array($item->id, $bought_ids);
        $can_afford     = $balance >= $item->cost;

        $card_class = $already_bought ? 'border-success' : ($can_afford ? '' : 'border-secondary');
        echo '<div class="col-md-4 col-sm-6 mb-3">';
        echo '<div class="card h-100 ' . $card_class . '">';
        echo '<div class="card-body text-center">';
        echo '<div style="font-size:3rem;line-height:1;">' . s($item->icon_emoji) . '</div>';
        echo '<h6 class="card-title mt-2 mb-1">' . s($item->name) . '</h6>';
        echo '<p class="text-muted small mb-2">' . s($item->description ?? '') . '</p>';
        echo '<div class="mb-3" style="font-size:1.2rem;font-weight:600;color:#f0a500;">🪙 ' . number_format($item->cost) . ' баллов</div>';

        if ($already_bought) {
            echo '<span class="badge badge-success p-2">✅ Уже куплено</span>';
        } elseif ($can_afford) {
            $buy_url = new moodle_url('/local/unics/pages/shop.php', [
                'buy'     => $item->id,
                'sesskey' => sesskey(),
            ]);
            echo html_writer::link($buy_url, 'Купить', [
                'class'   => 'btn btn-warning btn-sm font-weight-bold',
                'onclick' => "return confirm('Купить «" . addslashes(s($item->name)) . "» за " . $item->cost . " баллов?')",
            ]);
        } else {
            $need = $item->cost - $balance;
            echo '<button class="btn btn-outline-secondary btn-sm" disabled>Нужно ещё ' . number_format($need) . ' 🪙</button>';
        }

        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
}

// Мои покупки
if (!empty($purchases)) {
    echo '<h5 class="mt-4 mb-3">🎒 Мои приобретения</h5>';
    echo '<div class="d-flex flex-wrap gap-2">';
    foreach ($purchases as $p) {
        echo '<span class="badge badge-pill badge-warning p-2" style="font-size:.9rem;">'
           . s($p->icon_emoji) . ' ' . s($p->name) . '</span>';
    }
    echo '</div>';
}

// История баллов
if (!empty($history)) {
    echo '<h5 class="mt-4 mb-3">📋 История баллов</h5>';
    echo '<table class="table table-sm table-bordered">';
    echo '<thead class="thead-light"><tr><th>Дата</th><th>Событие</th><th class="text-right">Баллы</th></tr></thead>';
    echo '<tbody>';
    foreach ($history as $h) {
        $sign  = (int)$h->points > 0 ? '+' : '';
        $color = (int)$h->points > 0 ? 'success' : 'danger';
        echo '<tr>';
        echo '<td class="text-nowrap">' . userdate($h->created_at, '%d.%m.%Y') . '</td>';
        echo '<td>' . s($h->reason_text) . '</td>';
        echo '<td class="text-right"><span class="badge badge-' . $color . '">'
           . $sign . (int)$h->points . '</span></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

echo $OUTPUT->footer();
