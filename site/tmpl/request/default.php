<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Router\Route;
use SdpPortal\Component\ServiceDeskPlus\Site\View\Ui;

/** @var \SdpPortal\Component\ServiceDeskPlus\Site\View\Request\HtmlView $this */

// Layouts from the component, unless the site template overrides them (html/layouts/com_servicedeskplus).
$layoutOptions = ['component' => 'com_servicedeskplus', 'client' => 0];

$date = static fn (?int $ts): string => $ts ? HTMLHelper::_('date', gmdate('Y-m-d H:i:s', $ts), Text::_('DATE_FORMAT_LC2')) : '—';
$item = $this->item;
// Lookup fields that SDP leaves empty are shown the same way: "Not assigned".
$text  = fn (string $v): string => $this->escape($v !== '' ? $v : Text::_('COM_SERVICEDESKPLUS_UNASSIGNED'));
$field = static fn (string $labelKey, string $html): string => '<div><dt>' . Text::_($labelKey) . '</dt><dd>' . $html . '</dd></div>';
?>
<div class="com-servicedeskplus sdp-request" data-sdp-root="request">
    <a class="sdp-back" href="<?php echo Route::_('index.php?option=com_servicedeskplus&view=requests'); ?>"><span class="sdp-arrow" aria-hidden="true">&larr;</span> <?php echo Text::_('COM_SERVICEDESKPLUS_BACK_TO_REQUESTS'); ?></a>

    <?php if ($item === null) : ?>
        <div class="sdp-alert sdp-tone-red" role="alert"><?php echo $this->escape($this->error); ?></div>
    <?php else : ?>
        <div class="sdp-head">
            <div>
                <h1 class="h2"><?php echo $this->escape($item->subject); ?></h1>
                <div class="sdp-head-meta">
                    <span><?php echo Text::sprintf('COM_SERVICEDESKPLUS_REQUEST_TITLE', $this->escape($item->id)); ?></span>
                    <?php echo Ui::badge($item->status); ?>
                    <?php if ($item->approvalStatus !== '') : ?>
                        <?php echo Ui::badge($item->approvalStatus); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <section class="sdp-panel">
            <div class="sdp-panel-head"><h2 class="sdp-panel-title"><?php echo Text::_('COM_SERVICEDESKPLUS_DETAILS'); ?></h2></div>
            <dl class="sdp-fields">
                <?php
                echo $field('COM_SERVICEDESKPLUS_STATUS', Ui::badge($item->status));
                echo $field('COM_SERVICEDESKPLUS_PRIORITY', $text($item->priority));
                echo $field('COM_SERVICEDESKPLUS_URGENCY', $text($item->urgency));
                echo $field('COM_SERVICEDESKPLUS_TECHNICIAN_GROUP', $text($item->technicianGroup));
                echo $field('COM_SERVICEDESKPLUS_TECHNICIAN', $text($item->technician));
                echo $field('COM_SERVICEDESKPLUS_CATEGORY', $text($item->category));
                echo $field('COM_SERVICEDESKPLUS_SUBCATEGORY', $text($item->subcategory));
                echo $field('COM_SERVICEDESKPLUS_ITEM', $text($item->item));
                echo $field('COM_SERVICEDESKPLUS_CREATED', $date($item->createdAt));
                echo $field('COM_SERVICEDESKPLUS_UPDATED', $date($item->updatedAt));

                if ($item->approvalStatus !== '') {
                    echo $field('COM_SERVICEDESKPLUS_APPROVAL_STATUS', Ui::badge($item->approvalStatus));
                }

                if ($item->dueAt) {
                    echo $field('COM_SERVICEDESKPLUS_DUE', $date($item->dueAt));
                }

                if ($item->resolvedAt) {
                    echo $field('COM_SERVICEDESKPLUS_RESOLVED', $date($item->resolvedAt));
                }

                if ($item->completedAt) {
                    echo $field('COM_SERVICEDESKPLUS_COMPLETED', $date($item->completedAt));
                }
                ?>
            </dl>
        </section>

        <section class="sdp-panel">
            <div class="sdp-panel-head"><h2 class="sdp-panel-title"><?php echo Text::_('COM_SERVICEDESKPLUS_DESCRIPTION'); ?></h2></div>
            <div class="sdp-panel-body sdp-text sdp-prose"><?php echo nl2br($this->escape($item->description !== '' ? $item->description : '—')); ?></div>
        </section>

        <section class="sdp-panel">
            <div class="sdp-panel-head"><h2 class="sdp-panel-title"><?php echo Text::_('COM_SERVICEDESKPLUS_RESOLUTION'); ?></h2></div>
            <div class="sdp-panel-body sdp-text sdp-prose<?php echo $item->resolution === '' ? ' sdp-empty' : ''; ?>"><?php echo $item->resolution !== '' ? nl2br($this->escape($item->resolution)) : Text::_('COM_SERVICEDESKPLUS_NO_RESOLUTION'); ?></div>
        </section>

        <?php if ($this->approvals !== []) : ?>
            <section class="sdp-panel">
                <div class="sdp-panel-head"><h2 class="sdp-panel-title"><?php echo Text::_('COM_SERVICEDESKPLUS_REQUEST_APPROVALS'); ?></h2></div>
                <div class="sdp-table-wrap">
                    <table class="sdp-table sdp-request-approvals">
                        <caption class="visually-hidden"><?php echo Text::_('COM_SERVICEDESKPLUS_REQUEST_APPROVALS'); ?></caption>
                        <thead>
                            <tr>
                                <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_APPROVAL_LEVEL'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_APPROVER'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_STATUS'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_APPROVAL_SENT_ON'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_APPROVAL_ACTED_ON'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_APPROVAL_COMMENTS'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($this->approvals as $approval) : ?>
                            <tr>
                                <td><?php echo $this->escape(Text::sprintf('COM_SERVICEDESKPLUS_APPROVAL_LEVEL_N', $approval->levelNumber)
                                    . ($approval->levelName !== '' ? ' · ' . $approval->levelName : '')); ?></td>
                                <td><?php echo $this->escape($approval->approver !== '' ? $approval->approver : '—'); ?></td>
                                <td><?php echo Ui::badge($approval->status); ?></td>
                                <td class="sdp-nowrap"><?php echo $date($approval->sentOn); ?></td>
                                <td class="sdp-nowrap"><?php echo $date($approval->actedOn); ?></td>
                                <td class="sdp-text"><?php echo $approval->comments !== '' ? nl2br($this->escape($approval->comments)) : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <?php echo (new FileLayout('conversation', null, $layoutOptions))->render(['entries' => $this->conversation]); ?>
    <?php endif; ?>
</div>
