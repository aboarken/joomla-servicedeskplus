<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Discovery\Diagnostics;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Discovery\EndpointCatalog;

/** @var \SdpPortal\Component\ServiceDeskPlus\Administrator\View\Endpoints\HtmlView $this */

$action = Route::_('index.php?option=com_servicedeskplus&view=endpoints');
$token  = HTMLHelper::_('form.token');
$check  = $this->check;
$run    = $this->run;
$res    = $run['result'] ?? null;
$e      = fn (string $t): string => $this->escape($t);
$status = fn (?string $s): string => '<span class="badge ' . $this::badge($s) . '">' . Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_STATUS_' . strtoupper($s ?? 'none')) . '</span>';
$types  = [
    'date' => 'COM_SERVICEDESKPLUS_ENDPOINTS_TYPE_DATE', 'datetime' => 'COM_SERVICEDESKPLUS_ENDPOINTS_TYPE_DATETIME', 'time' => 'COM_SERVICEDESKPLUS_ENDPOINTS_TYPE_TIME',
    'number' => 'COM_SERVICEDESKPLUS_ENDPOINTS_TYPE_NUMBER', 'bool' => 'COM_SERVICEDESKPLUS_ENDPOINTS_TYPE_BOOL', 'text' => 'COM_SERVICEDESKPLUS_ENDPOINTS_TYPE_TEXT',
    'list' => 'COM_SERVICEDESKPLUS_ENDPOINTS_TYPE_LIST', 'empty' => 'COM_SERVICEDESKPLUS_ENDPOINTS_TYPE_EMPTY',
];
$detail = function (array $row) use ($e): string {
    if ($row['status'] !== Diagnostics::OK) {
        return $e((string) $row['error']);
    }

    return $e(Text::sprintf('COM_SERVICEDESKPLUS_ENDPOINTS_ROWS_TOTAL', (int) $row['rows'], $row['total'] === null ? '—' : (string) (int) $row['total']));
};
?>
<div class="sdp-endpoints">
    <p><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_INTRO'); ?></p>

    <form method="post" action="<?php echo $action; ?>" name="adminForm" id="adminForm" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="task" value="endpoints.run">
        <?php echo $token; ?>
        <div class="col-12 col-md-9">
            <label class="form-label" for="sdp-diag-email"><?php echo Text::_('COM_SERVICEDESKPLUS_DIAG_EMAIL'); ?></label>
            <input class="form-control" type="email" id="sdp-diag-email" name="email" maxlength="320" autocomplete="off" value="<?php echo $e((string) ($run['email'] ?? '')); ?>">
        </div>
        <div class="col-12 col-md-3"><button class="btn btn-primary w-100" type="submit" id="sdp-diag-run"><span class="icon-search" aria-hidden="true"></span> <?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_BUTTON'); ?></button></div>
    </form>

    <?php if ($res === null) : ?>
        <div class="alert alert-info"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_NOT_RUN'); ?></div>
    <?php else :
        $srv  = $run['server'];
        $prt  = $run['portal'];
        $conn = $res['connection'];
        ?>
        <?php foreach ($run['warnings'] as $warning) : ?>
            <div class="alert alert-warning"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_WARN_' . $warning); ?></div>
        <?php endforeach; ?>
        <?php if (!$conn['ok']) : ?>
            <div class="alert alert-danger">
                <?php echo Text::_($conn['kind'] !== '' ? 'COM_SERVICEDESKPLUS_ENDPOINTS_KIND_' . strtoupper($conn['kind']) : 'COM_SERVICEDESKPLUS_ENDPOINTS_RUN_FAILED'); ?>
                <div class="small"><code><?php echo $e((string) $conn['error']); ?></code></div>
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-3">
            <div class="col-lg-6">
                <section class="card h-100">
                    <div class="card-body">
                        <h2 class="h5"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_SERVER'); ?></h2>
                        <dl class="row mb-0">
                            <dt class="col-sm-5"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_ADDRESS'); ?></dt>
                            <dd class="col-sm-7"><code><?php echo $e((string) $srv['url']); ?></code>
                                <?php if (!$srv['https']) : ?><span class="badge bg-warning text-dark">http</span><?php endif; ?></dd>
                            <dt class="col-sm-5"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_CERT'); ?></dt>
                            <dd class="col-sm-7"><?php echo $srv['https'] ? Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_CERT_' . strtoupper((string) $srv['tls'])) : '—'; ?></dd>
                            <dt class="col-sm-5"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_VIA'); ?></dt>
                            <dd class="col-sm-7"><?php echo $srv['via'] !== '' ? '<code>' . $e((string) $srv['via']) . '</code>' : Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_DIRECT'); ?></dd>
                            <dt class="col-sm-5"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_TIMEOUTS'); ?></dt>
                            <dd class="col-sm-7"><?php echo Text::sprintf('COM_SERVICEDESKPLUS_ENDPOINTS_TIMEOUTS_VALUE', (int) $srv['timeout'], (int) $srv['connect']); ?></dd>
                            <dt class="col-sm-5"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_TIME'); ?></dt>
                            <dd class="col-sm-7"><?php echo (int) $conn['ms']; ?> ms</dd>
                            <dt class="col-sm-5"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_CHECKED_ON'); ?></dt>
                            <dd class="col-sm-7"><?php echo HTMLHelper::_('date', (int) $run['at'], Text::_('DATE_FORMAT_LC6')); ?></dd>
                        </dl>
                    </div>
                </section>
            </div>
            <div class="col-lg-6">
                <section class="card h-100">
                    <div class="card-body">
                        <h2 class="h5"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_KEY'); ?></h2>
                        <dl class="row mb-0">
                            <dt class="col-sm-5"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_KEY_STATUS'); ?></dt>
                            <dd class="col-sm-7"><?php echo $conn['ok']
                                ? '<span class="badge bg-success">' . Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_KEY_ACCEPTED') . '</span>'
                                : '<span class="badge bg-danger">' . Text::_($conn['kind'] !== '' ? 'COM_SERVICEDESKPLUS_ENDPOINTS_KIND_' . strtoupper($conn['kind']) : 'COM_SERVICEDESKPLUS_ENDPOINTS_STATUS_ERROR') . '</span>'; ?></dd>
                            <dt class="col-sm-5"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_VISIBLE'); ?></dt>
                            <dd class="col-sm-7"><?php echo $conn['total'] === null ? '—' : (int) $conn['total']; ?></dd>
                            <dt class="col-sm-5"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_ACCOUNT'); ?></dt>
                            <dd class="col-sm-7"><?php echo Text::_($prt['account'] ? 'COM_SERVICEDESKPLUS_ENDPOINTS_SET' : 'COM_SERVICEDESKPLUS_ENDPOINTS_NOT_SET'); ?></dd>
                            <dt class="col-sm-5"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_MODULES'); ?></dt>
                            <dd class="col-sm-7"><?php echo $prt['modules'] === [] ? '—' : $e(implode(', ', array_map(static fn (string $m): string => ucfirst(str_replace('_', ' ', $m)), $prt['modules']))); ?></dd>
                            <dt class="col-sm-5"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_MATCHING'); ?></dt>
                            <dd class="col-sm-7"><?php echo Text::_($prt['auto'] ? 'COM_SERVICEDESKPLUS_ENDPOINTS_MATCH_AUTO' : 'COM_SERVICEDESKPLUS_ENDPOINTS_MATCH_ADMIN'); ?></dd>
                            <dt class="col-sm-5"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_CACHE'); ?></dt>
                            <dd class="col-sm-7"><?php echo Text::sprintf('COM_SERVICEDESKPLUS_ENDPOINTS_CACHE_VALUE', (int) $prt['cache']); ?></dd>
                        </dl>
                    </div>
                </section>
            </div>
        </div>

        <?php if ($res['portal'] !== []) : ?>
            <section class="card mb-3">
                <div class="card-body">
                    <h2 class="h5"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_USES'); ?></h2>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr>
                                <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_ENDPOINT'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_USED_FOR'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_RESULT_COL'); ?></th>
                                <th scope="col" class="text-end"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_TIME'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_DETAIL'); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($res['portal'] as $row) : ?>
                                <tr>
                                    <th scope="row"><code><?php echo $e((string) $row['path']); ?></code></th>
                                    <td><?php echo $e((string) $row['portal']); ?></td>
                                    <td><?php echo $status((string) $row['status']); ?></td>
                                    <td class="text-end text-nowrap"><?php echo (int) $row['ms']; ?> ms</td>
                                    <td class="small"><?php echo $detail($row); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($res['requester'] !== null) : ?>
            <section class="card mb-3">
                <div class="card-body">
                    <h2 class="h5"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_REQUESTER'); ?></h2>
                    <dl class="row mb-0">
                        <?php foreach ($res['requester'] as $line) : ?>
                            <?php if ($line['value'] === '') : ?>
                                <dd class="col-12 <?php echo $line['ok'] ? '' : 'text-danger'; ?>"><?php echo $e((string) $line['label']); ?></dd>
                            <?php else : ?>
                                <dt class="col-sm-5"><?php echo $e((string) $line['label']); ?></dt>
                                <dd class="col-sm-7 <?php echo $line['ok'] ? '' : 'text-danger'; ?>"><?php echo $e((string) $line['value']); ?></dd>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </dl>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($res['stopped'] && $conn['ok']) : ?>
            <div class="alert alert-warning"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_STOPPED'); ?></div>
        <?php endif; ?>

    <?php endif; ?>

    <section class="card mb-3" id="sdp-advanced">
        <h2 class="card-header h5 mb-0"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_ADVANCED'); ?></h2>
        <div class="card-body">
            <h3 class="h5"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_OTHERS'); ?></h3>
            <p class="small text-muted"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_STEP1_HELP'); ?></p>
            <div class="table-responsive">
                <table class="table table-striped table-sm align-middle">
                    <thead><tr>
                        <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_PATH'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_SUMMARY'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_RESULT_COL'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_DETAIL'); ?></th>
                        <th scope="col"><span class="visually-hidden"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_CHECK'); ?></span></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($this->others() as $ep) : ?>
                        <tr>
                            <th scope="row"><code><?php echo $e((string) $ep['path']); ?></code></th>
                            <td class="small"><span class="text-muted"><?php echo $e((string) $ep['section']); ?>:</span> <?php echo $e((string) $ep['summary']); ?>
                                <?php if ($ep['doc'] !== '') : ?><a href="<?php echo $e((string) $ep['doc']); ?>" target="_blank" rel="noopener noreferrer"><span class="icon-out-2" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::sprintf('COM_SERVICEDESKPLUS_ENDPOINTS_DOC_LINK', $e((string) $ep['summary'])); ?></span></a><?php endif; ?></td>
                            <td><?php echo $status($ep['status']); ?></td>
                            <td class="small"><?php echo $ep['status'] === null ? '' : ($ep['status'] === Diagnostics::OK ? ($ep['total'] === null ? '' : Text::sprintf('COM_SERVICEDESKPLUS_ENDPOINTS_TOTAL', (int) $ep['total'])) : $e((string) $ep['error'])); ?></td>
                            <td class="text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="<?php echo Route::_('index.php?option=com_servicedeskplus&view=endpoints&' . http_build_query(['use' => $ep['path']])); ?>#sdp-check"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_SELECT'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
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
    <h3 class="h5 mt-4" id="sdp-check"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_STEP2'); ?></h3>
    <form method="post" action="<?php echo $action; ?>" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="task" value="endpoints.check">
        <?php echo $token; ?>
        <div class="col-12 col-lg-4">
            <label class="form-label" for="sdp-path"><?php echo Text::_('COM_SERVICEDESKPLUS_ENDPOINTS_PATH'); ?></label>
            <input class="form-control" id="sdp-path" name="path" required maxlength="300" value="<?php echo $e($form['path']); ?>" placeholder="/api/v3/requests" list="sdp-paths">
            <datalist id="sdp-paths"><?php foreach (EndpointCatalog::all() as $ep) : ?><option value="<?php echo $e($ep['path']); ?>"></option><?php endforeach; ?></datalist>
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
        <h4 class="h6"><?php echo $check['single']
            ? Text::sprintf('COM_SERVICEDESKPLUS_ENDPOINTS_RESULT_ONE', $e((string) $check['path']), $e((string) $check['key']))
            : Text::sprintf('COM_SERVICEDESKPLUS_ENDPOINTS_RESULT', $e((string) $check['path']), (int) $check['count'], $check['total'] === null ? '—' : (int) $check['total'], $e((string) $check['key'] ?: '—')); ?></h4>
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
    </section>

    <?php if ($res !== null) : ?>
        <section class="card mb-3" id="sdp-diag">
            <div class="card-body">
                <h2 class="h5"><?php echo Text::_('COM_SERVICEDESKPLUS_DIAG_REPORT'); ?></h2>
                <textarea class="form-control font-monospace small" id="sdp-diag-report" rows="12" readonly aria-label="<?php echo $e(Text::_('COM_SERVICEDESKPLUS_DIAG_REPORT')); ?>"><?php echo $e((string) $run['report']); ?></textarea>
                <form method="post" action="<?php echo $action; ?>" class="mt-2">
                    <input type="hidden" name="task" value="endpoints.download">
                    <?php echo $token; ?>
                    <button class="btn btn-secondary" type="submit"><span class="icon-download" aria-hidden="true"></span> <?php echo Text::_('COM_SERVICEDESKPLUS_DIAG_DOWNLOAD'); ?></button>
                </form>
                <p class="small text-muted mt-2 mb-0"><?php echo Text::_('COM_SERVICEDESKPLUS_DIAG_PRIVACY'); ?></p>
            </div>
        </section>
    <?php endif; ?>
</div>
