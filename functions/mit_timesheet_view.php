<?php

/*
 * Mountaineer IT - what the timesheet page (agent/mit_timesheet.php) and its saves
 * (agent/mit_timesheet_ajax.php) show: each day as data for the page's script (agent/js/mit_timesheet.js),
 * the summary numbers, and the codes a person can put time on. Required from functions/mit_timesheets.php.
 */

/** The codes for the day panel: code_id => [name, color, kind, paid, note_required, max, usable]. */
function mitTsViewCodes($user_id, array $period) {
    $allowed = mitTsCodesFor($user_id);
    $hidden = array_flip(mitTsHiddenCodes($user_id));
    $ca = mitTsCaOn($user_id, $period['end']);
    $codes = [];
    foreach (mitTsCodes(true) as $code_id => $code) {
        // On California rules meal periods are typed as times, so break / lunch codes are not offered
        $usable = isset($allowed[$code_id]) && $code['code_archived_at'] === null && !isset($hidden[$code_id]) && !($ca && $code['code_kind'] === 'break');
        $codes[intval($code_id)] = [
            'name' => (string) $code['code_name'],
            'color' => preg_match('/^#[0-9a-f]{3,8}$/i', (string) $code['code_color']) ? $code['code_color'] : '#6c757d',
            'kind' => (string) $code['code_kind'],
            'paid' => intval($code['code_paid']),
            'note' => intval($code['code_note_required'] ?? 0),
            'max' => intval($code['code_daily_max'] ?? 0),
            'usable' => $usable,
        ];
    }
    return $codes;
}

/**
 * The days of a period (or just $only dates) for the page: date => [label, closure, expected, future, tickets, codes,
 * break_paid, auto_lost, total, under, ca => [boxes, worked, meals, answers, meal_premium, rest_premium] | null].
 */
function mitTsViewDays($user_id, array $period, array $only = []) {
    $user_id = intval($user_id);
    $days = mitTsDays([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
    $entries = mitTsEntries([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
    $ca_days = mitTsCaDays([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
    $today = date('Y-m-d');
    $out = [];
    foreach ($days as $date => $d) {
        if ($only && !in_array($date, $only, true)) {
            continue;
        }
        $codes = [];
        foreach ($entries[$date] ?? [] as $code_id => $entry) {
            if ($entry['minutes'] || (string) $entry['note'] !== '') {
                $codes[intval($code_id)] = ['m' => intval($entry['minutes']), 'note' => (string) $entry['note']];
            }
        }
        $expected = mitTsExpected($user_id, $date);
        $ca = null;
        if (isset($ca_days[$date])) {
            $c = $ca_days[$date];
            $ca = ['boxes' => mitTsCaBoxes($c['periods']), 'worked' => $c['clocked'], 'meals' => $c['meals'], 'answers' => $c['answers'],
                'meal_premium' => $c['meal_premium'], 'rest_premium' => $c['rest_premium']];
        }
        // What the day adds up to: hours worked (the typed times on California rules), leave, and paid breaks
        $total = ($d['ca'] ? $d['clocked'] : $d['billable'] + $d['other'] + $d['work'] + $d['lost'] + $d['break_paid']) + $d['leave_paid'] + $d['leave_unpaid'];
        $out[$date] = [
            'dow' => date('D', strtotime($date)),
            'label' => date('M j', strtotime($date)),
            'long' => date('l, F j', strtotime($date)),
            'closure' => mitTsClosure($date),
            'expected' => $expected,
            'today' => $date === $today,
            'future' => $date > $today,
            'tickets' => ['billable' => intval($d['billable']), 'other' => intval($d['other'])],
            'codes' => $codes,
            'break_paid' => intval($d['break_paid']),
            'auto_lost' => intval($d['auto_lost']),
            'unallocated' => intval($d['unallocated']),
            'total' => $total,
            'under' => !$d['ca'] && $expected > 0 && $date <= $today && $total < $expected,
            'ca' => $ca,
        ];
    }
    return $out;
}

/** The summary numbers at the top of the page, as HTML. */
function mitTsViewSummary($user_id, array $period) {
    $user_id = intval($user_id);
    $t = mitTsTotals([$user_id], $period['start'], $period['end'])[$user_id];
    $settings = mitTsSettings();
    $stats = [
        [$t['ca'] ? 'Hours worked' : 'Total hours', mitTsHours($t['ca'] ? $t['worked'] : $t['total'], false),
            $t['ca'] ? ($t['leave'] ? '+ ' . mitTsHours($t['leave']) . ' h leave' : 'From your times') : ($t['break_paid'] ? 'incl. ' . mitTsHours($t['break_paid']) . ' h paid breaks' : 'Worked and leave')],
        ['Billable', mitTsBillableShare($t), mitTsHours($t['billable'], false) . ' h on billable tickets'],
    ];
    if ($t['ca'] || $settings['ot_day'] || $settings['ot_week']) {
        $stats[] = ['Overtime', mitTsHours($t['overtime'], false), $t['ca'] ? mitTsHours($t['double'], false) . ' h double time' : (mitTsPerson($user_id)['pay_type'] === 'salary' ? 'Salaried' : 'Over your limits')];
    }
    if ($t['ca']) {
        $premiums = intdiv($t['meal_premium'], 60) + intdiv($t['rest_premium'], 60);
        $stats[] = ['Premium pay', $premiums . ' h', $premiums ? intdiv($t['meal_premium'], 60) . ' meal · ' . intdiv($t['rest_premium'], 60) . ' rest' : 'No missed breaks', $premiums ? 'danger' : ''];
    } else {
        $stats[] = ['Lost time', mitTsHours($t['lost'], false), $t['auto_lost'] ? mitTsHours($t['auto_lost']) . ' h unaccounted' : 'Lost time codes', $t['lost'] ? 'warning' : ''];
    }
    $stats[] = ['Leave', mitTsHours($t['leave'], false), $t['leave'] > $t['leave_paid'] ? mitTsHours($t['leave'] - $t['leave_paid']) . ' h unpaid' : 'Paid'];
    $html = '<div class="mit-ts-stats">';
    foreach ($stats as $stat) {
        $html .= '<div class="mit-ts-stat"><div class="mit-ts-stat-label">' . escapeHtml($stat[0]) . '</div>'
            . '<div class="mit-ts-stat-value' . (!empty($stat[3]) ? ' text-' . $stat[3] : '') . '">' . escapeHtml($stat[1]) . '</div>'
            . '<div class="mit-ts-stat-sub">' . escapeHtml($stat[2]) . '</div></div>';
    }
    return $html . '</div>';
}
