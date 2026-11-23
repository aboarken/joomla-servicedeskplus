<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use SdpPortal\Component\ServiceDeskPlus\Site\View\Ui;

/** @var \SdpPortal\Component\ServiceDeskPlus\Site\View\Asset\HtmlView $this */

$date  = static fn (?int $ts): string => $ts ? HTMLHelper::_('date', gmdate('Y-m-d H:i:s', $ts), Text::_('DATE_FORMAT_LC4')) : '—';
$text  = fn (string $v): string => $v !== '' ? $this->escape($v) : '—';
$field = static fn (string $labelKey, string $html): string => '<div><dt>' . Text::_($labelKey) . '</dt><dd>' . $html . '</dd></div>';
$item  = $this->item;
?>
<div class="com-servicedeskplus sdp-asset" data-sdp-root="asset">
    <a class="sdp-back" href="<?php echo Route::_('index.php?option=com_servicedeskplus&view=assets'); ?>"><span class="sdp-arrow" aria-hidden="true">&larr;</span> <?php echo Text::_('COM_SERVICEDESKPLUS_BACK_TO_ASSETS'); ?></a>

    <?php if ($item === null) : ?>
        <div class="sdp-alert sdp-tone-red" role="alert"><?php echo $this->escape($this->error); ?></div>
    <?php else :
        $overdue = $item->isOverdue($this->now);
        $dueSoon = !$overdue && $item->isDueSoon($this->now);
        ?>
        <div class="sdp-head">
            <div>
                <h1 class="h2"><?php echo $text($item->name); ?></h1>
                <div class="sdp-head-meta">
                    <?php if ($item->type !== '') : ?>
                        <span><?php echo $this->escape($item->type); ?></span>
                    <?php endif; ?>
                    <?php if ($overdue) : ?>
                        <?php echo Ui::badge(Text::_('COM_SERVICEDESKPLUS_LOAN_OVERDUE'), 'red'); ?>
                    <?php elseif ($dueSoon) : ?>
                        <?php echo Ui::badge(Text::_('COM_SERVICEDESKPLUS_LOAN_DUE_SOON'), 'amber'); ?>
                    <?php else : ?>
                        <?php echo Ui::badge(Text::_('COM_SERVICEDESKPLUS_LOAN_ON_LOAN'), 'green'); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($overdue) : ?>
            <div class="sdp-alert sdp-tone-red" role="alert"><?php echo Text::_('COM_SERVICEDESKPLUS_LOAN_OVERDUE_NOTICE'); ?></div>
        <?php endif; ?>

        <section class="sdp-panel">
            <div class="sdp-panel-head"><h2 class="sdp-panel-title"><?php echo Text::_('COM_SERVICEDESKPLUS_LOAN_DETAILS'); ?></h2></div>
            <dl class="sdp-fields">
                <?php
                echo $field('COM_SERVICEDESKPLUS_ASSET_LOANED_TO', $text($item->loanedTo));
                echo $field('COM_SERVICEDESKPLUS_ASSET_DEPARTMENT', $text($item->department));
                echo $field('COM_SERVICEDESKPLUS_LOAN_START', $date($item->loanStart));
                echo $field('COM_SERVICEDESKPLUS_LOAN_DUE', $date($item->loanEnd)
                    . ($overdue ? ' ' . Ui::badge(Text::_('COM_SERVICEDESKPLUS_LOAN_OVERDUE'), 'red') : ''));
                ?>
            </dl>
        </section>

        <section class="sdp-panel">
            <div class="sdp-panel-head"><h2 class="sdp-panel-title"><?php echo Text::_('COM_SERVICEDESKPLUS_ASSET_DETAILS'); ?></h2></div>
            <dl class="sdp-fields">
                <?php
                echo $field('COM_SERVICEDESKPLUS_ASSET_TYPE', $text($item->type));
                echo $field('COM_SERVICEDESKPLUS_ASSET_PRODUCT', $text($item->product));
                echo $field('COM_SERVICEDESKPLUS_ASSET_SERIAL', $text($item->serialNumber));
                echo $field('COM_SERVICEDESKPLUS_ASSET_STATE', Ui::badge($item->state, 'slate'));
                echo $field('COM_SERVICEDESKPLUS_ASSET_PURCHASE_DATE', $date($item->purchaseDate));
                echo $field('COM_SERVICEDESKPLUS_ASSET_WARRANTY_EXPIRY', $date($item->warrantyExpiry)
                    . ($item->warrantyExpiry !== null && $item->warrantyExpiry < $this->now ? ' ' . Ui::badge(Text::_('COM_SERVICEDESKPLUS_ASSET_WARRANTY_EXPIRED'), 'muted') : ''));
                ?>
            </dl>
        </section>
    <?php endif; ?>
</div>
