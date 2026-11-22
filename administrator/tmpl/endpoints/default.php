<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \SdpPortal\Component\ServiceDeskPlus\Administrator\View\Endpoints\HtmlView $this */

$action = Route::_('index.php?option=com_servicedeskplus');
$token  = HTMLHelper::_('form.token');
$check  = $this->check;
$diag   = $this->diagnostics;
$e      = fn (string $t): string => $this->escape($t);
$types  = [
    'date' => 'COM_SERVICEDESKPLUS_ENDPOINTS_TYPE_DATE', 'datetime' => 'COM_SERVICEDESKPLUS_ENDPOINTS_TYPE_DATETIME', 'time' => 'COM_SERVICEDESKPLUS_ENDPOINTS_TYPE_TIME',
    'number' => 'COM_SERVICEDESKPLUS_ENDPOINTS_TYPE_NUMBER', 'bool' => 'COM_SERVICEDESKPLUS_ENDPOINTS_TYPE_BOOL', 'text' => 'COM_SERVICEDESKPLUS_ENDPOINTS_TYPE_TEXT',
    'list' => 'COM_SERVICEDESKPLUS_ENDPOINTS_TYPE_LIST', 'empty' => 'COM_SERVICEDESKPLUS_ENDPOINTS_TYPE_EMPTY',
];
?>
<div class="sdp-endpoints">
    <p><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_INTRO'); ?></p>

    <section class="card mb-4" id="sdp-diag">
        <div class="card-body">
            <h2 class="h4 card-title"><?php echo Text::_('COM_SERVICEDESKPLUS_DIAG_TITLE'); ?></h2>
            <p><?php echo Text::_('COM_SERVICEDESKPLUS_DIAG_INTRO'); ?></p>
            <form method="post" action="<?php echo $action; ?>" class="row g-2 align-items-end">
                <input type="hidden" name="task" value="endpoints.diagnose">
                <?php echo $token; ?>
                <div class="col-12 col-md-9">
                    <label class="form-label" for="sdp-diag-email"><?php echo Text::_('COM_SERVICEDESKPLUS_DIAG_EMAIL'); ?></label>
                    <input class="form-control" type="email" id="sdp-diag-email" name="email" maxlength="320" autocomplete="off" value="<?php echo $e($diag['email'] ?? ''); ?>">
                </div>
                <div class="col-12 col-md-3"><button class="btn btn-primary w-100" type="submit" id="sdp-diag-run"><span class="icon-wrench" aria-hidden="true"></span> <?php echo Text::_('COM_SERVICEDESKPLUS_DIAG_RUN'); ?></button></div>
            </form>
            <?php if ($diag !== null) : ?>
                <label class="form-label mt-3" for="sdp-diag-report"><?php echo Text::_('COM_SERVICEDESKPLUS_DIAG_REPORT'); ?></label>
                <textarea class="form-control font-monospace small" id="sdp-diag-report" rows="18" readonly><?php echo $e($diag['report']); ?></textarea>
                <button class="btn btn-secondary mt-2" type="button" id="sdp-diag-copy" data-done="<?php echo $e(Text::_('COM_SERVICEDESKPLUS_DIAG_COPIED')); ?>"><span class="icon-copy" aria-hidden="true"></span> <?php echo Text::_('COM_SERVICEDESKPLUS_DIAG_COPY'); ?></button>
                <p class="small text-muted mt-2 mb-0"><?php echo Text::_('COM_SERVICEDESKPLUS_DIAG_PRIVACY'); ?></p>
            <?php endif; ?>
        </div>
    </section>

    <h2 class="h4"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_STEP1'); ?></h2>
    <p class="small text-muted"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_STEP1_HELP'); ?></p>
    <form method="post" action="<?php echo $action; ?>" class="mb-3">
        <input type="hidden" name="task" value="endpoints.catalog">
        <?php echo $token; ?>
        <button class="btn btn-primary" type="submit"><span class="icon-search" aria-hidden="true"></span> <?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_READ_LIST'); ?></button>
        <?php if ($this->catalog !== null) : ?>
            <span class="small text-muted ms-2"><?php echo Text::sprintf('COM_SERVICEDESKPLUS_ENDPOINTS_CHECKED_AT', HTMLHelper::_('date', (int) $this->catalog['at'], Text::_('DATE_FORMAT_LC2'))); ?></span>
        <?php endif; ?>
    </form>

    <form method="get" action="<?php echo Route::_('index.php'); ?>" class="row g-2 align-items-end mb-2">
        <input type="hidden" name="option" value="com_servicedeskplus">
        <input type="hidden" name="view" value="endpoints">
        <div class="col-auto">
            <label class="form-label" for="sdp-filter"><?php echo Text::_('JSEARCH_FILTER'); ?></label>
            <input class="form-control" id="sdp-filter" name="filter" value="<?php echo $e($this->filter); ?>" placeholder="requests, assets">
        </div>
        <div class="col-auto"><button class="btn btn-secondary" type="submit"><?php echo Text::_('JSEARCH_FILTER_SUBMIT'); ?></button></div>
    </form>
    <div class="table-responsive mb-4">
        <table class="table table-striped table-sm align-middle">
            <caption class="visually-hidden"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_STEP1'); ?></caption>
            <thead><tr>
                <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_PATH'); ?></th>
                <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_SUMMARY'); ?></th>
                <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_USED_BY'); ?></th>
                <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_ANSWER'); ?></th>
                <th scope="col"><span class="visually-hidden"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_CHECK'); ?></span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($this->endpoints() as $ep) :
                $r = $this->resultFor($ep['path']);
                ?>
                <tr>
                    <th scope="row"><code><?php echo $e($ep['path']); ?></code><?php if ($ep['portal'] !== '') : ?> <span class="badge bg-info text-dark"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_PORTAL'); ?></span><?php endif; ?></th>
                    <td class="small"><span class="text-muted"><?php echo $e($ep['section']); ?>:</span> <?php echo $e($ep['summary']); ?>
                        <?php if ($ep['doc'] !== '') : ?><a href="<?php echo $e($ep['doc']); ?>" target="_blank" rel="noopener noreferrer"><span class="icon-out-2" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::sprintf('COM_SERVICEDESKPLUS_ENDPOINTS_DOC_LINK', $e($ep['summary'])); ?></span></a><?php endif; ?></td>
                    <td class="small"><?php echo $e($ep['portal']); ?></td>
                    <td class="small">
                        <?php if ($r === null) : ?>
                            <span class="text-muted">—</span>
                        <?php elseif ($r['ok']) : ?>
                            <span class="badge bg-success"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_OK'); ?></span>
                            <?php echo $r['total'] !== null ? Text::sprintf('COM_SERVICEDESKPLUS_ENDPOINTS_TOTAL', (int) $r['total']) : ''; ?>
                        <?php else : ?>
                            <span class="badge bg-warning text-dark"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_KIND_' . strtoupper($r['kind'])); ?></span>
                            <?php echo $r['http'] > 0 ? 'HTTP ' . (int) $r['http'] : ''; ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                        <a class="btn btn-sm btn-outline-primary" href="<?php echo Route::_('index.php?option=com_servicedeskplus&view=endpoints&' . http_build_query(['use' => $ep['path']])); ?>#sdp-check"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_SELECT'); ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($this->endpoints() === []) : ?><p class="text-muted"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_NONE_SHOWN'); ?></p><?php endif; ?>
    </div>

    <?php
    // Step 2: the check form, pre-filled from "Select" (GET) or from the last check.
    $form = [
        'path'         => $this->selected ?: ($check['path'] ?? '/api/v3/requests'),
        'search_field' => $check['search_field'] ?? '',
        'search_value' => $check['search_value'] ?? '',
        'rows'         => (int) ($check['rows'] ?? 10),
    ];
    ?>
    <h2 class="h4" id="sdp-check"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_STEP2'); ?></h2>
    <form method="post" action="<?php echo $action; ?>" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="task" value="endpoints.check">
        <?php echo $token; ?>
        <div class="col-12 col-lg-4">
            <label class="form-label" for="sdp-path"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_PATH'); ?></label>
            <input class="form-control" id="sdp-path" name="path" required maxlength="300" value="<?php echo $e($form['path']); ?>" placeholder="/api/v3/requests" list="sdp-paths">
            <datalist id="sdp-paths"><?php foreach ($this->endpoints() as $ep) : ?><option value="<?php echo $e($ep['path']); ?>"></option><?php endforeach; ?></datalist>
        </div>
        <div class="col-6 col-lg-3">
            <label class="form-label" for="sdp-sf"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_SEARCH_FIELD'); ?></label>
            <input class="form-control" id="sdp-sf" name="search_field" maxlength="200" value="<?php echo $e($form['search_field']); ?>" placeholder="status.name">
        </div>
        <div class="col-6 col-lg-2">
            <label class="form-label" for="sdp-sv"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_SEARCH_VALUE'); ?></label>
            <input class="form-control" id="sdp-sv" name="search_value" maxlength="100" autocomplete="off" value="<?php echo $e($form['search_value']); ?>" placeholder="Open">
        </div>
        <div class="col-6 col-lg-2">
            <label class="form-label" for="sdp-rows"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_ROWS'); ?></label>
            <input class="form-control" type="number" min="1" max="50" id="sdp-rows" name="rows" value="<?php echo $form['rows']; ?>">
        </div>
        <div class="col-6 col-lg-1"><button class="btn btn-primary w-100" type="submit"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_CHECK'); ?></button></div>
        <p class="col-12 small text-muted mb-0"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_CHECK_HELP'); ?></p>
    </form>

    <?php if ($check !== null && $check['error'] === null) : ?>
        <h3 class="h5"><?php echo $check['single']
            ? Text::sprintf('COM_SERVICEDESKPLUS_ENDPOINTS_RESULT_ONE', $e((string) $check['path']), $e((string) $check['key']))
            : Text::sprintf('COM_SERVICEDESKPLUS_ENDPOINTS_RESULT', $e((string) $check['path']), (int) $check['count'], $check['total'] === null ? '—' : (int) $check['total'], $e((string) $check['key'] ?: '—')); ?></h3>
        <?php if ($check['fields'] === []) : ?>
            <div class="alert alert-info"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_NO_ROWS'); ?></div>
        <?php else : ?>
            <div class="table-responsive mb-3">
                <table class="table table-striped table-sm">
                    <caption class="visually-hidden"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_FIELDS'); ?></caption>
                    <thead><tr>
                        <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_FIELD'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_KIND_OF_VALUE'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_VALUES'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($check['fields'] as $path => $info) : ?>
                        <tr>
                            <th scope="row"><code><?php echo $e((string) $path); ?></code></th>
                            <td><?php echo Text::_($types[$info['type']] ?? 'COM_SERVICEDESKPLUS_ENDPOINTS_TYPE_TEXT'); ?></td>
                            <td class="small"><?php echo $e(implode(' · ', $info['values'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small text-muted"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_PRIVACY'); ?></p>
        <?php endif; ?>
    <?php endif; ?>
</div>
