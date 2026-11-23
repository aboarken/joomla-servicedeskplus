<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use SdpPortal\Component\ServiceDeskPlus\Site\View\Ui;

/** @var \SdpPortal\Component\ServiceDeskPlus\Site\View\Announcement\HtmlView $this */

$date  = static fn (?int $ts): string => $ts ? HTMLHelper::_('date', gmdate('Y-m-d H:i:s', $ts), Text::_('DATE_FORMAT_LC2')) : '';
$field = static fn (string $labelKey, string $html): string => '<div><dt>' . Text::_($labelKey) . '</dt><dd>' . $html . '</dd></div>';
$item  = $this->item;
?>
<div class="com-servicedeskplus sdp-announcement" data-sdp-root="announcement">
    <a class="sdp-back" href="<?php echo Route::_('index.php?option=com_servicedeskplus&view=announcements'); ?>"><span class="sdp-arrow" aria-hidden="true">&larr;</span> <?php echo Text::_('COM_SERVICEDESKPLUS_BACK_TO_ANNOUNCEMENTS'); ?></a>

    <?php if ($item === null) : ?>
        <div class="sdp-alert sdp-tone-red" role="alert"><?php echo $this->escape($this->error); ?></div>
    <?php else : ?>
        <div class="sdp-head">
            <div>
                <h1 class="h2"><?php echo $this->escape($item->title !== '' ? $item->title : Text::_('COM_SERVICEDESKPLUS_ANNOUNCEMENT_UNTITLED')); ?></h1>
                <div class="sdp-head-meta">
                    <span><?php echo Text::sprintf('COM_SERVICEDESKPLUS_ANNOUNCEMENT_ID', $this->escape($item->id)); ?></span>
                    <?php if ($item->type !== '') : ?>
                        <?php echo Ui::badge($item->type, 'teal'); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <section class="sdp-panel">
            <dl class="sdp-fields">
                <?php
                if ($date($item->date()) !== '') {
                    echo $field('COM_SERVICEDESKPLUS_ANNOUNCEMENT_START', $date($item->date()));
                }

                echo $field('COM_SERVICEDESKPLUS_ANNOUNCEMENT_END', $item->toTime !== null ? $date($item->toTime) : Text::_('COM_SERVICEDESKPLUS_ANNOUNCEMENT_NO_END'));

                if ($item->createdBy !== '') {
                    echo $field('COM_SERVICEDESKPLUS_ANNOUNCEMENT_POSTED_BY', $this->escape($item->createdBy));
                }

                if ($item->priority !== '') {
                    echo $field('COM_SERVICEDESKPLUS_PRIORITY', $this->escape($item->priority));
                }
                ?>
            </dl>
        </section>

        <section class="sdp-panel">
            <div class="sdp-panel-body sdp-text sdp-prose"><?php echo nl2br($this->escape($item->content !== '' ? $item->content : '—')); ?></div>
        </section>
    <?php endif; ?>
</div>
