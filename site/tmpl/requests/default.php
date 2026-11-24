<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Requests\RequestQuery;
use SdpPortal\Component\ServiceDeskPlus\Site\View\Ui;

/** @var \SdpPortal\Component\ServiceDeskPlus\Site\View\Requests\HtmlView $this */

$q      = $this->query;
$date   = static fn (?int $ts): string => $ts ? HTMLHelper::_('date', gmdate('Y-m-d H:i:s', $ts), Text::_('DATE_FORMAT_LC5')) : '—';
$sorts  = [
    'created_desc' => 'COM_SERVICEDESKPLUS_SORT_CREATED_DESC',
    'created_asc'  => 'COM_SERVICEDESKPLUS_SORT_CREATED_ASC',
    'updated_desc' => 'COM_SERVICEDESKPLUS_SORT_UPDATED_DESC',
    'updated_asc'  => 'COM_SERVICEDESKPLUS_SORT_UPDATED_ASC',
];
$currentSort = $q->sort . '_' . $q->direction;
$types       = [
    ''                          => 'COM_SERVICEDESKPLUS_REQUEST_TYPE_ALL',
    RequestQuery::TYPE_INCIDENT => 'COM_SERVICEDESKPLUS_REQUEST_TYPE_INCIDENT',
    RequestQuery::TYPE_SERVICE  => 'COM_SERVICEDESKPLUS_REQUEST_TYPE_SERVICE',
];
?>
<div class="com-servicedeskplus sdp-requests" data-sdp-root="requests">
    <div class="sdp-head">
        <?php if ($this->showPageHeading) : ?>
            <h1 class="h2"><?php echo $this->escape($this->pageHeading); ?></h1>
        <?php else : ?>
            <span></span>
        <?php endif; ?>
        <?php if ($this->canCreate) : ?>
            <a class="sdp-btn" href="<?php echo Route::_('index.php?option=com_servicedeskplus&view=requestform'); ?>">
                + <?php echo Text::_('COM_SERVICEDESKPLUS_SUBMIT_A_REQUEST'); ?>
            </a>
        <?php endif; ?>
    </div>

    <?php // Filters are applied on change, the search on Enter (media/js/filters.js). ?>
    <form class="sdp-toolbar" method="get" data-sdp-filters autocomplete="off" role="search" action="<?php echo Route::_('index.php?option=com_servicedeskplus&view=requests'); ?>">
        <div class="sdp-field--search">
            <label class="sdp-label" for="sdp-search"><?php echo Text::_('COM_SERVICEDESKPLUS_SEARCH'); ?></label>
            <input class="sdp-input" type="search" id="sdp-search" name="search" maxlength="100" enterkeyhint="search"
                   value="<?php echo $this->escape($q->search); ?>"
                   placeholder="<?php echo $this->escape(Text::_('COM_SERVICEDESKPLUS_SEARCH_HINT')); ?>">
        </div>
        <div>
            <label class="sdp-label" for="sdp-type"><?php echo Text::_('COM_SERVICEDESKPLUS_REQUEST_TYPE'); ?></label>
            <select class="sdp-input" id="sdp-type" name="type">
                <?php foreach ($types as $value => $label) : ?>
                    <option value="<?php echo $value; ?>" <?php echo $value === $q->type ? 'selected' : ''; ?>><?php echo Text::_($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="sdp-label" for="sdp-category"><?php echo Text::_('COM_SERVICEDESKPLUS_CATEGORY'); ?></label>
            <select class="sdp-input" id="sdp-category" name="category">
                <option value="" <?php echo $q->category === '' ? 'selected' : ''; ?>><?php echo Text::_('COM_SERVICEDESKPLUS_ALL_CATEGORIES'); ?></option>
                <?php foreach ($this->categories as $category) : ?>
                    <option value="<?php echo $this->escape($category); ?>" <?php echo strcasecmp($category, $q->category) === 0 ? 'selected' : ''; ?>><?php echo $this->escape($category); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="sdp-label" for="sdp-status"><?php echo Text::_('COM_SERVICEDESKPLUS_STATUS'); ?></label>
            <select class="sdp-input" id="sdp-status" name="status">
                <option value="<?php echo RequestQuery::ALL_STATUSES; ?>" <?php echo $q->status === '' ? 'selected' : ''; ?>><?php echo Text::_('COM_SERVICEDESKPLUS_ALL_STATUSES'); ?></option>
                <?php foreach ($this->statuses as $status) : ?>
                    <option value="<?php echo $this->escape($status); ?>" <?php echo $status === $q->status ? 'selected' : ''; ?>><?php echo $this->escape($status); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="sdp-label" for="sdp-sort"><?php echo Text::_('COM_SERVICEDESKPLUS_SORT'); ?></label>
            <select class="sdp-input" id="sdp-sort" name="sort">
                <?php foreach ($sorts as $value => $label) : ?>
                    <option value="<?php echo $value; ?>" <?php echo $value === $currentSort ? 'selected' : ''; ?>><?php echo Text::_($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php
    // A card per category with its number of open and on-hold requests (as on the Dashboard);
    // a card shows that category in the list, with the status and type chosen above; the
    // selected card again shows all categories.
    $tones = ['blue', 'purple', 'teal', 'amber', 'green', 'red'];
    $cards = [];

    if ($this->categoryTotals !== []) {
        $i = 0;

        foreach ($this->categoryTotals as $category => $count) {
            if ($category === '') {
                continue;
            }

            // Requests without a category: counted, but the list cannot filter on "no category".
            if ($category === "\0none") {
                $cards[] = ['label' => Text::_('COM_SERVICEDESKPLUS_CHART_CATEGORY_NONE'), 'count' => $count, 'url' => $this->categoryUrl(''), 'tone' => 'muted', 'active' => false];

                continue;
            }

            $cards[] = [
                'label'  => (string) $category,
                'count'  => $count,
                'url'    => $this->categoryUrl(strcasecmp($q->category, (string) $category) === 0 ? '' : (string) $category),
                'tone'   => $tones[$i++ % \count($tones)],
                'active' => strcasecmp($q->category, (string) $category) === 0,
            ];
        }
    }

    echo (new \Joomla\CMS\Layout\FileLayout('summary', null, ['component' => 'com_servicedeskplus', 'client' => 0]))->render(['cards' => $cards, 'label' => Text::_('COM_SERVICEDESKPLUS_CATEGORY')]);
    ?>

    <?php if ($this->error !== '') : ?>
        <div class="sdp-alert sdp-tone-red" role="alert"><?php echo $this->escape($this->error); ?></div>
    <?php else : ?>
        <section class="sdp-panel">
            <?php if ($this->result === null || $this->result->items === []) : ?>
                <p class="sdp-panel-body sdp-empty"><?php echo Text::_('COM_SERVICEDESKPLUS_REQUESTS_NONE'); ?></p>
            <?php else : ?>
                <div class="sdp-table-wrap">
                    <table class="sdp-table">
                        <caption class="visually-hidden"><?php echo Text::_('COM_SERVICEDESKPLUS_REQUESTS_TITLE'); ?></caption>
                        <thead>
                            <tr>
                                <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_ID'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_SUBJECT'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_STATUS'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_TECHNICIAN'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_CREATED'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_UPDATED'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($this->result->items as $item) :
                            $url = Route::_('index.php?option=com_servicedeskplus&view=request&id=' . rawurlencode($item->id));
                            ?>
                            <tr>
                                <td class="sdp-id"><a href="<?php echo $url; ?>"><?php echo $this->escape($item->id); ?></a></td>
                                <td><a href="<?php echo $url; ?>"><?php echo $this->escape($item->subject); ?></a></td>
                                <td><?php echo Ui::badge($item->status); ?></td>
                                <td><?php echo $this->escape($item->technician !== '' ? $item->technician : Text::_('COM_SERVICEDESKPLUS_UNASSIGNED')); ?></td>
                                <td class="sdp-nowrap"><?php echo $date($item->createdAt); ?></td>
                                <td class="sdp-nowrap"><?php echo $date($item->updatedAt); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($this->result !== null && ($this->result->page > 1 || $this->result->hasMore)) :
        $pages = $this->result->total !== null ? (int) ceil($this->result->total / $q->limit) : null;
        ?>
        <nav aria-label="<?php echo $this->escape(Text::_('JLIB_HTML_PAGINATION')); ?>">
            <ul class="pagination">
                <li class="page-item <?php echo $this->result->page <= 1 ? 'disabled' : ''; ?>">
                    <?php if ($this->result->page > 1) : ?>
                        <a class="page-link" href="<?php echo $this->pageUrl($this->result->page - 1); ?>"><span class="sdp-arrow" aria-hidden="true">&larr;</span> <?php echo Text::_('JPREV'); ?></a>
                    <?php else : ?>
                        <span class="page-link" aria-disabled="true"><span class="sdp-arrow" aria-hidden="true">&larr;</span> <?php echo Text::_('JPREV'); ?></span>
                    <?php endif; ?>
                </li>
                <li class="page-item disabled">
                    <span class="page-link">
                        <?php echo $pages ? Text::sprintf('COM_SERVICEDESKPLUS_PAGE_N_OF_M', $this->result->page, $pages) : Text::sprintf('COM_SERVICEDESKPLUS_PAGE_N', $this->result->page); ?>
                    </span>
                </li>
                <li class="page-item <?php echo $this->result->hasMore ? '' : 'disabled'; ?>">
                    <?php if ($this->result->hasMore) : ?>
                        <a class="page-link" href="<?php echo $this->pageUrl($this->result->page + 1); ?>"><?php echo Text::_('JNEXT'); ?> <span class="sdp-arrow" aria-hidden="true">&rarr;</span></a>
                    <?php else : ?>
                        <span class="page-link" aria-disabled="true"><?php echo Text::_('JNEXT'); ?> <span class="sdp-arrow" aria-hidden="true">&rarr;</span></span>
                    <?php endif; ?>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>
