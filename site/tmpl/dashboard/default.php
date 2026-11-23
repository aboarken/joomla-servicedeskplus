<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Approvals\ApprovalModule;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Requests\RequestQuery;

/** @var \SdpPortal\Component\ServiceDeskPlus\Site\View\Dashboard\HtmlView $this */

/*
 * Layout: the user's details (department, job title, employee ID, name), summary cards, charts
 * (open requests by category, by age and by priority), then My Requests, My Approvals and
 * My Loans side by side. Styles are in media/com_servicedeskplus/css/portal.css (.sdp-*), so the
 * Dashboard looks the same on any site template. Colours ("tones"): blue (#17426f) open, amber on
 * hold or due soon, green resolved / approved / nothing overdue, slate closed, purple waiting
 * approval, red rejected / overdue, muted for zero.
 */

$e    = fn (string $text): string => $this->escape($text);
$r    = $this->requests;

// Summary card: the whole card opens its page.
$stat = static fn (string $label, int $value, string $url, string $tone): string => '<a class="sdp-stat sdp-tone-' . $tone . '" href="' . $url . '">'
    . '<span class="sdp-stat-label">' . $label . '</span>'
    . '<span class="sdp-stat-value">' . $value . '</span></a>';

// Row of a panel list: label and a coloured count; the whole row opens its page.
$row = static function (string $label, ?int $value, string $url, string $tone, bool $strong = false): string {
    $cls = 'sdp-list-row' . ($strong ? ' sdp-list-row--strong' : '');

    if ($value === null) {
        return '<li><span class="' . $cls . '"><span>' . $label . '</span><span class="sdp-pill sdp-tone-muted">—</span></span></li>';
    }

    return '<li><a class="' . $cls . '" href="' . $url . '"><span>' . $label . '</span>'
        . '<span class="sdp-pill sdp-tone-' . ($value === 0 && $tone !== 'green' ? 'muted' : $tone) . '">' . $value . '</span></a></li>';
};

$panelHead = static fn (string $title, string $url = '', string $linkText = ''): string => '<div class="sdp-panel-head">'
    . '<h2 class="sdp-panel-title">' . $title . '</h2>'
    . ($url !== '' ? '<a class="sdp-panel-link" href="' . $url . '">' . $linkText . ' <span class="sdp-arrow" aria-hidden="true">&rarr;</span></a>' : '')
    . '</div>';

$viewAll = Text::_('COM_SERVICEDESKPLUS_DASHBOARD_VIEW_ALL');
$error   = fn (string $message): string => '<div class="sdp-panel-body"><div class="sdp-alert sdp-tone-red" role="alert">' . $e($message) . '</div></div>';
// My Requests tiles: bucket => label, tone.
$tiles = [
    'open'     => ['COM_SERVICEDESKPLUS_DASHBOARD_OPEN', 'blue'],
    'on_hold'  => ['COM_SERVICEDESKPLUS_DASHBOARD_ON_HOLD', 'amber'],
    'resolved' => ['COM_SERVICEDESKPLUS_DASHBOARD_RESOLVED', 'green'],
    'closed'   => ['COM_SERVICEDESKPLUS_DASHBOARD_CLOSED', 'slate'],
];
?>
<div class="com-servicedeskplus sdp-dashboard" data-sdp-root="dashboard">
    <?php if ($this->showPageHeading) : ?>
        <h1 class="h2 mb-3"><?php echo $e($this->pageHeading); ?></h1>
    <?php endif; ?>

    <?php // The user's own ServiceDesk Plus record, as a panel: a title, then each label above its value. Empty fields are left out. ?>
    <?php if ($this->profile !== null) :
        $p      = $this->profile;
        $fields = array_filter([
            'COM_SERVICEDESKPLUS_PROFILE_DEPARTMENT'  => $p->department,
            'COM_SERVICEDESKPLUS_PROFILE_JOB_TITLE'   => $p->jobTitle,
            'COM_SERVICEDESKPLUS_PROFILE_EMPLOYEE_ID' => $p->employeeId,
            'COM_SERVICEDESKPLUS_PROFILE_NAME'        => $p->name !== '' ? $p->name : $this->userName,
        ], static fn (string $value): bool => trim($value) !== '');
        ?>
        <?php if ($fields !== []) : ?>
            <section class="sdp-panel sdp-profile" aria-labelledby="sdp-profile-title">
                <div class="sdp-panel-head">
                    <h2 class="sdp-panel-title" id="sdp-profile-title"><?php echo Text::_('COM_SERVICEDESKPLUS_PROFILE_TITLE'); ?></h2>
                </div>
                <dl class="sdp-profile-list">
                    <?php foreach ($fields as $labelKey => $value) : ?>
                        <div class="sdp-profile-item">
                            <dt><?php echo Text::_($labelKey); ?></dt>
                            <dd><?php echo $e($value); ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            </section>
        <?php endif; ?>
    <?php endif; ?>

    <?php // Summary cards ?>
    <div class="sdp-stats">
        <?php if ($r !== null) :
            echo $stat(Text::_('COM_SERVICEDESKPLUS_DASHBOARD_OPEN_REQUESTS'), $r->service['open'] + $r->incident['open'], $this->statusUrl('open'), 'blue');
            echo $stat(Text::_('COM_SERVICEDESKPLUS_DASHBOARD_ON_HOLD_REQUESTS'), $r->service['on_hold'] + $r->incident['on_hold'], $this->statusUrl('on_hold'), 'amber');
        endif; ?>
        <?php if ($this->showApprovals && $this->approvalsError === '') :
            $waiting = (int) ($this->approvals['total'] ?? 0);
            echo $stat(Text::_('COM_SERVICEDESKPLUS_DASHBOARD_WAITING_APPROVAL'), $waiting, $this->url('approvals'), $waiting > 0 ? 'purple' : 'green');
        endif; ?>
        <?php if ($this->showLoans && $this->loansError === '') :
            echo $stat(Text::_('COM_SERVICEDESKPLUS_DASHBOARD_LOANS_TO_RETURN'), $this->loansDueSoon, $this->url('assets&show=due_soon'), $this->loansOverdue > 0 ? 'red' : ($this->loansDueSoon > 0 ? 'amber' : 'green'));
        endif; ?>
    </div>

    <?php // The charts, then My Requests, My Approvals and My Loans: one grid, two cards per row
          // (one on small screens); a card alone in the last row takes the full width. ?>
    <div class="sdp-panels sdp-charts">
    <?php // Charts from the requests read for the figures above (no extra ServiceDesk Plus calls) ?>
    <?php if ($this->showRequests && $r !== null && $this->requestsError === '') :
        $num = static fn (?int $n): string => $n === null ? '—' : number_format($n);
        $pct = static fn (int $value, int $total): float => $total > 0 ? round(100 * $value / $total, 2) : 0.0;

        // Open and on-hold requests by category: a donut of every category, the most frequent
        // first, then those without a category, with a legend of names, counts and shares. Each
        // category has a colour from the validated palette: up to six categories, chosen from the
        // name (kept when counts change; a clash takes the next free colour); more than six, in
        // turn around the ring, so neighbouring slices differ.
        $catRows = [];
        $catUsed = [];

        $catNamed = $r->category['named'] ?? [];

        foreach ($catNamed as $i => $c) {
            // More categories than colours: colours in turn along the row, so neighbours differ.
            if (count($catNamed) > 6) {
                $slot = $i % 6;
            } else {
                $slot = crc32(mb_strtolower($c['name'])) % 6;

                while (isset($catUsed[$slot])) {
                    $slot = ($slot + 1) % 6;
                }
            }

            $catUsed[$slot] = true;
            $catRows[]      = ['label' => $c['name'], 'count' => $c['count'], 'tone' => 'c' . ($slot + 1)];
        }

        if (($r->category['other'] ?? 0) > 0) {
            $catRows[] = ['label' => Text::_('COM_SERVICEDESKPLUS_CHART_CATEGORY_OTHER'), 'count' => (int) $r->category['other'], 'tone' => 'other'];
        }

        if (($r->category['none'] ?? 0) > 0) {
            $catRows[] = ['label' => Text::_('COM_SERVICEDESKPLUS_CHART_CATEGORY_NONE'), 'count' => (int) $r->category['none'], 'tone' => 'none'];
        }

        $catTotal = array_sum(array_column($catRows, 'count'));

        // Open requests by age: a donut; ordinal buckets in one warm ramp, light gold = newer,
        // deep red = oldest (the colour darkens with age).
        $ageLabels = [
            'week'    => 'COM_SERVICEDESKPLUS_CHART_AGE_WEEK',
            'month'   => 'COM_SERVICEDESKPLUS_CHART_AGE_MONTH',
            'quarter' => 'COM_SERVICEDESKPLUS_CHART_AGE_QUARTER',
            'older'   => 'COM_SERVICEDESKPLUS_CHART_AGE_OLDER',
        ];
        $ageTotal = array_sum($r->age);
        $radius   = 42;
        $circ     = 2 * M_PI * $radius;

        // Open requests by priority: vertical bars, one per priority. A known priority always has the same
        // colour (validated order: no two neighbours too alike); other names take the free colours.
        $fixedSlots = ['low' => 1, 'normal' => 2, 'medium' => 3, 'high' => 4, 'urgent' => 5, 'critical' => 6];
        $freeSlots  = array_values(array_diff([1, 2, 3, 4, 5, 6], array_values(array_intersect_key($fixedSlots, array_flip(array_map(static fn (array $pr): string => mb_strtolower(trim($pr['name'])), $r->priority))))));
        $priorities = [];

        foreach ($r->priority as $pr) {
            $key  = mb_strtolower(trim($pr['name']));
            $tone = $pr['other'] ? 'other' : ($pr['name'] === '' ? 'none' : (string) ($fixedSlots[$key] ?? (array_shift($freeSlots) ?? 6)));
            $priorities[] = $pr + [
                'label' => $pr['other'] ? Text::_('COM_SERVICEDESKPLUS_CHART_PRIORITY_OTHER') : ($pr['name'] !== '' ? $pr['name'] : Text::_('COM_SERVICEDESKPLUS_CHART_PRIORITY_NONE')),
                'tone'  => $tone,
            ];
        }

        $priorityTotal = array_sum(array_column($priorities, 'count'));
        ?>
            <section class="sdp-panel sdp-chart" aria-labelledby="sdp-chart-category">
                <?php echo str_replace('<h2 class="sdp-panel-title">', '<h2 class="sdp-panel-title" id="sdp-chart-category">', $panelHead(Text::_('COM_SERVICEDESKPLUS_CHART_CATEGORY_TITLE'))); ?>
                <div class="sdp-panel-body">
                    <p class="sdp-chart-note"><?php echo Text::_('COM_SERVICEDESKPLUS_CHART_ACTIVE_NOTE'); ?></p>
                    <?php if ($catTotal === 0) : ?>
                        <p class="sdp-empty"><?php echo Text::_($r->category === [] ? 'COM_SERVICEDESKPLUS_CHART_CATEGORY_PENDING' : 'COM_SERVICEDESKPLUS_CHART_EMPTY'); ?></p>
                    <?php else : ?>
                        <div class="sdp-donut-wrap">
                            <svg class="sdp-donut" viewBox="0 0 120 120" aria-hidden="true" focusable="false">
                                <circle class="sdp-donut-track" cx="60" cy="60" r="42"></circle>
                                <?php
                                $catCirc   = 2 * M_PI * 42;
                                $catOffset = 0.0;
                                $catShown  = count(array_filter(array_column($catRows, 'count')));

                                foreach ($catRows as $c) :
                                    if ($c['count'] === 0) {
                                        continue;
                                    }

                                    $length = $catCirc * $c['count'] / $catTotal;
                                    // 2px surface gap between slices (none when one slice is the whole ring).
                                    $dash   = $catShown > 1 ? max(0.5, $length - 2) : $length;
                                    ?>
                                    <circle class="sdp-donut-slice sdp-cat-stroke-<?php echo $c['tone']; ?>" cx="60" cy="60" r="42"
                                        stroke-dasharray="<?php echo round($dash, 3) . ' ' . round($catCirc - $dash, 3); ?>"
                                        stroke-dashoffset="<?php echo round(-$catOffset, 3); ?>"><title><?php echo $e(Text::sprintf('COM_SERVICEDESKPLUS_CHART_TIP_SHARE', $c['label'], $num($c['count']), round($pct($c['count'], $catTotal)))); ?></title></circle>
                                    <?php $catOffset += $length; endforeach; ?>
                                <text class="sdp-donut-total" x="60" y="58" text-anchor="middle"><?php echo $num($catTotal); ?></text>
                                <text class="sdp-donut-caption" x="60" y="74" text-anchor="middle"><?php echo $e(Text::_('COM_SERVICEDESKPLUS_CHART_DONUT_CAPTION')); ?></text>
                            </svg>
                            <ul class="sdp-share-legend sdp-share-legend--long">
                                <?php foreach ($catRows as $c) : ?>
                                    <li>
                                        <span class="sdp-swatch sdp-cat-<?php echo $c['tone'] === 'none' ? 'none' : ($c['tone'] === 'other' ? 'other' : substr($c['tone'], 1)); ?>" aria-hidden="true"></span>
                                        <span class="sdp-share-label<?php echo in_array($c['tone'], ['none', 'other'], true) ? ' sdp-share-label--muted' : ''; ?>" title="<?php echo $e($c['label']); ?>"><?php echo $e($c['label']); ?></span>
                                        <span class="sdp-share-value"><?php echo $num($c['count']); ?></span>
                                        <span class="sdp-share-pct"><?php echo round($pct($c['count'], $catTotal)); ?>%</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="sdp-panel sdp-chart" aria-labelledby="sdp-chart-age">
                <?php echo str_replace('<h2 class="sdp-panel-title">', '<h2 class="sdp-panel-title" id="sdp-chart-age">', $panelHead(Text::_('COM_SERVICEDESKPLUS_CHART_AGE_TITLE'))); ?>
                <div class="sdp-panel-body">
                    <p class="sdp-chart-note"><?php echo Text::_('COM_SERVICEDESKPLUS_CHART_ACTIVE_NOTE'); ?></p>
                    <?php if ($ageTotal === 0) : ?>
                        <p class="sdp-empty"><?php echo Text::_('COM_SERVICEDESKPLUS_CHART_EMPTY'); ?></p>
                    <?php else : ?>
                        <div class="sdp-donut-wrap">
                            <svg class="sdp-donut" viewBox="0 0 120 120" aria-hidden="true" focusable="false">
                                <circle class="sdp-donut-track" cx="60" cy="60" r="<?php echo $radius; ?>"></circle>
                                <?php
                                $offset = 0.0;
                                $shown  = count(array_filter($r->age));
                                $step   = 0;

                                foreach ($ageLabels as $key => $labelKey) :
                                    $step++;
                                    $n = (int) ($r->age[$key] ?? 0);

                                    if ($n === 0) {
                                        continue;
                                    }

                                    $length = $circ * $n / $ageTotal;
                                    // 2px surface gap between slices (none when one slice is the whole ring).
                                    $dash   = $shown > 1 ? max(0.5, $length - 2) : $length;
                                    ?>
                                    <circle class="sdp-donut-slice sdp-seq-stroke-<?php echo $step; ?>" cx="60" cy="60" r="<?php echo $radius; ?>"
                                        stroke-dasharray="<?php echo round($dash, 3) . ' ' . round($circ - $dash, 3); ?>"
                                        stroke-dashoffset="<?php echo round(-$offset, 3); ?>"><title><?php echo $e(Text::sprintf('COM_SERVICEDESKPLUS_CHART_TIP_SHARE', Text::_($labelKey), $num($n), round($pct($n, $ageTotal)))); ?></title></circle>
                                    <?php $offset += $length; endforeach; ?>
                                <text class="sdp-donut-total" x="60" y="58" text-anchor="middle"><?php echo $num($ageTotal); ?></text>
                                <text class="sdp-donut-caption" x="60" y="74" text-anchor="middle"><?php echo $e(Text::_('COM_SERVICEDESKPLUS_CHART_DONUT_CAPTION')); ?></text>
                            </svg>
                            <ul class="sdp-share-legend">
                                <?php $step = 0; foreach ($ageLabels as $key => $labelKey) : $step++; $n = (int) ($r->age[$key] ?? 0); ?>
                                    <li>
                                        <span class="sdp-swatch sdp-seq-<?php echo $step; ?>" aria-hidden="true"></span>
                                        <span class="sdp-share-label"><?php echo Text::_($labelKey); ?></span>
                                        <span class="sdp-share-value"><?php echo $num($n); ?></span>
                                        <span class="sdp-share-pct"><?php echo round($pct($n, $ageTotal)); ?>%</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="sdp-panel sdp-chart" aria-labelledby="sdp-chart-priority">
                <?php echo str_replace('<h2 class="sdp-panel-title">', '<h2 class="sdp-panel-title" id="sdp-chart-priority">', $panelHead(Text::_('COM_SERVICEDESKPLUS_CHART_PRIORITY_TITLE'))); ?>
                <div class="sdp-panel-body">
                    <p class="sdp-chart-note"><?php echo Text::_('COM_SERVICEDESKPLUS_CHART_ACTIVE_NOTE'); ?></p>
                    <?php if ($priorityTotal === 0) : ?>
                        <p class="sdp-empty"><?php echo Text::_('COM_SERVICEDESKPLUS_CHART_EMPTY'); ?></p>
                    <?php else : ?>
                        <?php $prioMax = max(1, ...(array_column($priorities, 'count') ?: [1])); ?>
                        <ul class="sdp-vbars" style="--sdp-vbars: <?php echo count($priorities); ?>">
                            <?php foreach ($priorities as $pr) : ?>
                                <li class="sdp-vbar sdp-vbar--<?php echo $pr['other'] ? 'other' : ($pr['name'] === '' ? 'none' : 'named'); ?>"
                                    data-sdp-tip="<?php echo $e(Text::sprintf('COM_SERVICEDESKPLUS_CHART_TIP_SHARE', $pr['label'], $num($pr['count']), round($pct($pr['count'], $priorityTotal)))); ?>">
                                    <span class="sdp-vbar-track" aria-hidden="true">
                                        <span class="sdp-vbar-value"><?php echo $num($pr['count']); ?></span>
                                        <span class="sdp-vbar-fill sdp-cat-<?php echo $pr['tone']; ?>" style="height: <?php echo max(2, $pct($pr['count'], $prioMax)); ?>%"></span>
                                    </span>
                                    <span class="visually-hidden"><?php echo $e($pr['label']); ?>: <?php echo $num($pr['count']); ?> (<?php echo round($pct($pr['count'], $priorityTotal)); ?>%)</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php // Colour key: each priority's colour, name, count and share. ?>
                        <ul class="sdp-share-legend sdp-share-legend--key">
                            <?php foreach ($priorities as $pr) : ?>
                                <li>
                                    <span class="sdp-swatch sdp-cat-<?php echo $pr['tone']; ?>" aria-hidden="true"></span>
                                    <span class="sdp-share-label"><?php echo $e($pr['label']); ?></span>
                                    <span class="sdp-share-value"><?php echo $num($pr['count']); ?></span>
                                    <span class="sdp-share-pct"><?php echo round($pct($pr['count'], $priorityTotal)); ?>%</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>
    <?php endif; ?>

        <?php if ($this->showRequests) : ?>
            <section class="sdp-panel">
                <?php echo $panelHead(Text::_('COM_SERVICEDESKPLUS_DASHBOARD_REQUESTS'), $this->url('requests&status=all'), $viewAll); ?>
                <?php if ($this->requestsError !== '') : ?>
                    <?php echo $error($this->requestsError); ?>
                <?php elseif ($r !== null) : ?>
                    <div class="sdp-panel-body">
                        <div class="sdp-tiles">
                            <?php foreach ($tiles as $bucket => [$labelKey, $tone]) :
                                // Open and on hold: counted from the rows read; resolved and closed: counted by SDP.
                                $counted = $r->counted[$bucket] ?? null;
                                $i       = $counted !== null ? $counted['incident'] : (int) ($r->incident[$bucket] ?? 0);
                                $s       = $counted !== null ? $counted['service'] : (int) ($r->service[$bucket] ?? 0);
                                $total   = $counted !== null ? $counted['total'] : $i + $s;
                                ?>
                                <div class="sdp-tile sdp-tone-<?php echo $total ? $tone : 'muted'; ?>">
                                    <a class="sdp-tile-main" href="<?php echo $this->statusUrl($bucket); ?>">
                                        <span class="sdp-tile-value"><?php echo $total === null ? '—' : number_format($total); ?></span>
                                        <span class="sdp-tile-label"><?php echo Text::_($labelKey); ?></span>
                                    </a>
                                    <?php if ($i !== null && $s !== null) : ?>
                                        <div class="sdp-tile-meta">
                                            <a href="<?php echo $this->statusUrl($bucket, RequestQuery::TYPE_INCIDENT); ?>"><?php echo Text::sprintf('COM_SERVICEDESKPLUS_DASHBOARD_N_INCIDENT', number_format($i)); ?></a>
                                            <a href="<?php echo $this->statusUrl($bucket, RequestQuery::TYPE_SERVICE); ?>"><?php echo Text::sprintf('COM_SERVICEDESKPLUS_DASHBOARD_N_SERVICE', number_format($s)); ?></a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($r->truncated) : ?>
                            <p class="sdp-note"><?php echo Text::_('COM_SERVICEDESKPLUS_DASHBOARD_TRUNCATED'); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if ($this->canCreate) : ?>
                        <div class="sdp-panel-foot">
                            <a class="sdp-action" href="<?php echo $this->url('requestform'); ?>">+ <?php echo Text::_('COM_SERVICEDESKPLUS_SUBMIT_A_REQUEST'); ?></a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($this->showApprovals) : ?>
            <section class="sdp-panel">
                <?php echo $panelHead(Text::_('COM_SERVICEDESKPLUS_DASHBOARD_APPROVALS'), $this->url('approvals'), $viewAll); ?>
                <?php if ($this->approvalsError !== '') : ?>
                    <?php echo $error($this->approvalsError); ?>
                <?php endif; ?>
                <ul class="sdp-list">
                    <?php if ($this->approvalsError === '') : ?>
                        <?php foreach ($this->approvalModules as $module) :
                            echo $row(Text::_(ApprovalModule::labelKey($module)), (int) ($this->approvals[$module] ?? 0), $this->url('approvals&type=' . $module), 'purple');
                        endforeach; ?>
                        <?php echo $row(Text::_('COM_SERVICEDESKPLUS_DASHBOARD_WAITING_APPROVAL'), (int) ($this->approvals['total'] ?? 0), $this->url('approvals'), 'purple', true); ?>
                    <?php endif; ?>
                    <li class="sdp-list-sep"><?php echo Text::_('COM_SERVICEDESKPLUS_DASHBOARD_MY_DECISIONS'); ?></li>
                    <?php echo $row(Text::_('COM_SERVICEDESKPLUS_APPROVALS_SHOW_APPROVED'), $this->decisions['approved'], $this->url('approvals&show=approved'), 'green'); ?>
                    <?php echo $row(Text::_('COM_SERVICEDESKPLUS_APPROVALS_SHOW_REJECTED'), $this->decisions['rejected'], $this->url('approvals&show=rejected'), 'red'); ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if ($this->showLoans) : ?>
            <section class="sdp-panel">
                <?php echo $panelHead(Text::_('COM_SERVICEDESKPLUS_DASHBOARD_LOANS'), $this->url('assets'), $viewAll); ?>
                <?php if ($this->loansError !== '') : ?>
                    <?php echo $error($this->loansError); ?>
                <?php else : ?>
                    <ul class="sdp-list">
                        <?php echo $row(Text::_('COM_SERVICEDESKPLUS_LOANS_SHOW_ALL'), $this->loansTotal, $this->url('assets'), 'blue'); ?>
                        <?php echo $row(Text::_('COM_SERVICEDESKPLUS_LOANS_SHOW_DUE_SOON'), $this->loansDueSoon, $this->url('assets&show=due_soon'), 'amber'); ?>
                        <?php echo $row(Text::_('COM_SERVICEDESKPLUS_LOANS_SHOW_OVERDUE'), $this->loansOverdue, $this->url('assets&show=overdue'), $this->loansOverdue > 0 ? 'red' : 'green'); ?>
                        <?php echo $row(Text::_('COM_SERVICEDESKPLUS_LOANS_SHOW_PREVIOUS'), $this->loansReturned, $this->url('assets&show=previous'), 'slate'); ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</div>
