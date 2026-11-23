<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Router\Route;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Approvals\ApprovalModule;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Approvals\ApprovalService;
use SdpPortal\Component\ServiceDeskPlus\Site\View\Ui;

/** @var \SdpPortal\Component\ServiceDeskPlus\Site\View\Approval\HtmlView $this */

// Layouts from the component, unless the site template overrides them (html/layouts/com_servicedeskplus).
$layoutOptions = ['component' => 'com_servicedeskplus', 'client' => 0];

$date  = static fn (?int $ts): string => $ts ? HTMLHelper::_('date', gmdate('Y-m-d H:i:s', $ts), Text::_('DATE_FORMAT_LC2')) : '—';
$item  = $this->item;
$field = static fn (string $labelKey, string $html): string => '<div><dt>' . Text::_($labelKey) . '</dt><dd>' . $html . '</dd></div>';
?>
<div class="com-servicedeskplus sdp-approval" data-sdp-root="approval">
    <?php echo (new FileLayout('messages', null, $layoutOptions))->render(); ?>
    <a class="sdp-back" href="<?php echo Route::_('index.php?option=com_servicedeskplus&view=approvals'); ?>"><span class="sdp-arrow" aria-hidden="true">&larr;</span> <?php echo Text::_('COM_SERVICEDESKPLUS_BACK_TO_APPROVALS'); ?></a>

    <?php if ($item === null) : ?>
        <div class="sdp-alert sdp-tone-red" role="alert"><?php echo $this->escape($this->error); ?></div>
    <?php else : ?>
        <div class="sdp-head">
            <div>
                <h1 class="h2"><?php echo $this->escape($item->subject); ?></h1>
                <div class="sdp-head-meta">
                    <span><?php echo Text::sprintf('COM_SERVICEDESKPLUS_APPROVAL_ITEM', Text::_(ApprovalModule::labelKey($item->module)), $this->escape($item->requestId)); ?></span>
                    <?php echo Ui::badge($item->status); ?>
                </div>
            </div>
        </div>

        <section class="sdp-panel">
            <div class="sdp-panel-head"><h2 class="sdp-panel-title"><?php echo Text::_('COM_SERVICEDESKPLUS_DETAILS'); ?></h2></div>
            <dl class="sdp-fields">
                <?php
                echo $field('COM_SERVICEDESKPLUS_APPROVAL_TYPE', Ui::badge(Text::_(ApprovalModule::labelKey($item->module)), 'purple'));
                echo $field('COM_SERVICEDESKPLUS_REQUESTER', $this->escape($item->requester !== '' ? $item->requester : '—'));
                echo $field('COM_SERVICEDESKPLUS_APPROVAL_SENT_ON', $date($item->sentOn));
                echo $field('COM_SERVICEDESKPLUS_STATUS', Ui::badge($item->status));
                ?>
            </dl>
        </section>

        <section class="sdp-panel">
            <div class="sdp-panel-head"><h2 class="sdp-panel-title"><?php echo Text::_('COM_SERVICEDESKPLUS_DESCRIPTION'); ?></h2></div>
            <?php if ($item->purchaseOrder !== null) : ?>
                <?php // A purchase order has no description: its remarks and its terms, each under its own label. ?>
                <dl class="sdp-fields sdp-fields--stacked">
                    <?php
                    echo $field('COM_SERVICEDESKPLUS_PO_REMARKS', '<div class="sdp-text sdp-prose">' . nl2br($this->escape($item->purchaseOrder->remarks !== '' ? $item->purchaseOrder->remarks : '—')) . '</div>');
                    echo $field('COM_SERVICEDESKPLUS_PO_TERMS', '<div class="sdp-text sdp-prose">' . nl2br($this->escape($item->purchaseOrder->terms !== '' ? $item->purchaseOrder->terms : '—')) . '</div>');
                    ?>
                </dl>
            <?php else : ?>
                <div class="sdp-panel-body sdp-text sdp-prose"><?php echo nl2br($this->escape($item->description !== '' ? $item->description : '—')); ?></div>
            <?php endif; ?>
        </section>

        <?php if ($item->purchaseOrder !== null) : $po = $item->purchaseOrder; ?>
            <?php if ($po->fields !== []) : ?>
                <section class="sdp-panel">
                    <div class="sdp-panel-head"><h2 class="sdp-panel-title"><?php echo Text::_('COM_SERVICEDESKPLUS_PO_INFO'); ?></h2></div>
                    <dl class="sdp-fields">
                        <?php foreach ($po->fields as [$labelKey, $value]) :
                            echo $field($labelKey, nl2br($this->escape($value)));
                        endforeach; ?>
                    </dl>
                </section>
            <?php endif; ?>

            <?php if ($po->items !== [] || $po->totals !== []) : ?>
                <section class="sdp-panel">
                    <div class="sdp-panel-head"><h2 class="sdp-panel-title"><?php echo Text::_('COM_SERVICEDESKPLUS_PO_ITEMS'); ?></h2></div>
                    <div class="sdp-table-wrap">
                        <table class="sdp-table sdp-po-items">
                            <thead><tr>
                                <th scope="col"><?php echo Text::_('COM_SERVICEDESKPLUS_PO_ITEM'); ?></th>
                                <th scope="col" class="sdp-num"><?php echo Text::_('COM_SERVICEDESKPLUS_PO_QUANTITY'); ?></th>
                                <th scope="col" class="sdp-num"><?php echo Text::_('COM_SERVICEDESKPLUS_PO_PRICE'); ?></th>
                                <th scope="col" class="sdp-num"><?php echo Text::_('COM_SERVICEDESKPLUS_PO_AMOUNT'); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($po->items as $row) : ?>
                                <tr>
                                    <td>
                                        <?php echo $this->escape($row['name'] !== '' ? $row['name'] : '—'); ?>
                                        <?php if ($row['description'] !== '') : ?>
                                            <div class="sdp-muted"><?php echo nl2br($this->escape($row['description'])); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="sdp-num"><?php echo $this->escape($row['quantity']); ?></td>
                                    <td class="sdp-num"><?php echo $this->escape($row['price']); ?></td>
                                    <td class="sdp-num"><?php echo $this->escape($row['total']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <?php if ($po->totals !== []) : ?>
                                <tfoot>
                                <?php foreach ($po->totals as $i => [$labelKey, $amount]) : ?>
                                    <tr<?php echo $i === array_key_last($po->totals) ? ' class="sdp-po-total"' : ''; ?>>
                                        <th scope="row" colspan="3" class="sdp-num"><?php echo Text::_($labelKey); ?></th>
                                        <td class="sdp-num"><?php echo $this->escape(trim($po->currency . ' ' . $amount)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($item->module !== ApprovalModule::PURCHASE_ORDER || $item->attachments !== []) : ?>
            <section class="sdp-panel">
                <div class="sdp-panel-head"><h2 class="sdp-panel-title"><?php echo Text::_('COM_SERVICEDESKPLUS_APPROVAL_ATTACHMENTS'); ?></h2></div>
                <div class="sdp-panel-body">
                    <?php if ($item->attachments === null) : ?>
                        <p class="sdp-muted"><?php echo Text::_('COM_SERVICEDESKPLUS_APPROVAL_ATTACHMENTS_UNAVAILABLE'); ?></p>
                    <?php elseif ($item->attachments === []) : ?>
                        <p class="sdp-muted"><?php echo Text::_('COM_SERVICEDESKPLUS_APPROVAL_ATTACHMENTS_NONE'); ?></p>
                    <?php else : ?>
                        <ul class="sdp-attachments">
                            <?php foreach ($item->attachments as $file) : ?>
                                <?php
                                $href = Route::_('index.php?option=com_servicedeskplus&task=approval.attachment&id=' . rawurlencode($item->approvalId)
                                    . '&request_id=' . rawurlencode($item->requestId) . '&level_id=' . rawurlencode($item->levelId)
                                    . ($item->module !== ApprovalModule::REQUEST ? '&module=' . rawurlencode($item->module) : '')
                                    . '&file=' . rawurlencode($file['id']));
                                ?>
                                <?php $kind = \SdpPortal\Component\ServiceDeskPlus\Administrator\Approvals\AttachmentPreview::kind($file['name']); ?>
                                <li>
                                    <a href="<?php echo $href; ?>" download rel="nofollow"
                                        <?php if ($kind !== '') : ?>
                                            data-sdp-preview="<?php echo $kind; ?>"
                                            data-sdp-preview-name="<?php echo $this->escape($file['name']); ?>"
                                            data-sdp-preview-open="<?php echo $this->escape(Text::_('COM_SERVICEDESKPLUS_PREVIEW_OPEN')); ?>"
                                            data-sdp-preview-download="<?php echo $this->escape(Text::_('COM_SERVICEDESKPLUS_PREVIEW_DOWNLOAD')); ?>"
                                            data-sdp-preview-close="<?php echo $this->escape(Text::_('COM_SERVICEDESKPLUS_PREVIEW_CLOSE')); ?>"
                                        <?php endif; ?>><span aria-hidden="true">&#128206;</span> <?php echo $this->escape($file['name']); ?></a>
                                    <?php if ($file['size'] > 0) : ?>
                                        <span class="sdp-muted">(<?php echo HTMLHelper::_('number.bytes', $file['size'], 'auto', 1); ?>)</span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($item->module === ApprovalModule::REQUEST) : ?>
            <?php echo (new FileLayout('conversation', null, $layoutOptions))->render(['entries' => $this->conversation]); ?>
        <?php endif; ?>

        <section class="sdp-panel">
            <div class="sdp-panel-head"><h2 class="sdp-panel-title"><?php echo Text::_('COM_SERVICEDESKPLUS_APPROVAL_COMMENTS'); ?></h2></div>
            <div class="sdp-panel-body sdp-text sdp-prose"><?php echo $item->comments !== '' ? nl2br($this->escape($item->comments)) : '—'; ?></div>
        </section>

        <?php if (!$item->pending) : ?>
            <div class="sdp-alert sdp-tone-slate"><?php echo Text::_('COM_SERVICEDESKPLUS_APPROVAL_NOT_PENDING'); ?></div>
        <?php elseif (!$this->canDecide) : ?>
            <?php // No decide permission: read-only. ?>
        <?php else : ?>
            <section class="sdp-panel">
                <div class="sdp-panel-head"><h2 class="sdp-panel-title"><?php echo Text::_('COM_SERVICEDESKPLUS_APPROVAL_DECISION'); ?></h2></div>
                <form class="sdp-panel-body" action="<?php echo Route::_('index.php?option=com_servicedeskplus'); ?>" method="post" data-sdp-submit-once>
                    <div class="sdp-form-field">
                        <label class="sdp-label" for="sdp-comment"><?php echo Text::_('COM_SERVICEDESKPLUS_APPROVAL_COMMENT'); ?></label>
                        <textarea class="sdp-input" id="sdp-comment" name="comment" rows="3" maxlength="<?php echo ApprovalService::COMMENT_MAX; ?>"></textarea>
                    </div>

                    <input type="hidden" name="decision" value="" data-sdp-decision>
                    <div class="sdp-actions">
                        <button class="sdp-btn sdp-btn--green" type="submit" name="decision" value="approve"
                                data-sdp-confirm="<?php echo $this->escape(Text::_('COM_SERVICEDESKPLUS_APPROVAL_CONFIRM_APPROVE')); ?>">
                            <span class="spinner-border spinner-border-sm d-none" aria-hidden="true" data-sdp-spinner></span>
                            <span data-sdp-label><span aria-hidden="true">&#10003;</span> <?php echo Text::_('COM_SERVICEDESKPLUS_APPROVE'); ?></span>
                        </button>
                        <button class="sdp-btn sdp-btn--red" type="submit" name="decision" value="reject"
                                data-sdp-confirm="<?php echo $this->escape(Text::_('COM_SERVICEDESKPLUS_APPROVAL_CONFIRM_REJECT')); ?>">
                            <span class="spinner-border spinner-border-sm d-none" aria-hidden="true" data-sdp-spinner></span>
                            <span data-sdp-label><span aria-hidden="true">&#10005;</span> <?php echo Text::_('COM_SERVICEDESKPLUS_REJECT'); ?></span>
                        </button>
                    </div>

                    <input type="hidden" name="task" value="approval.decide">
                    <input type="hidden" name="request_id" value="<?php echo $this->escape($item->requestId); ?>">
                    <input type="hidden" name="level_id" value="<?php echo $this->escape($item->levelId); ?>">
                    <input type="hidden" name="approval_id" value="<?php echo $this->escape($item->approvalId); ?>">
                    <input type="hidden" name="module" value="<?php echo $this->escape($item->module); ?>">
                    <input type="hidden" name="submission" value="<?php echo $this->escape($this->submissionToken); ?>">
                    <?php echo HTMLHelper::_('form.token'); ?>
                </form>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</div>
