<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Requests\AttachmentValidator;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Requests\RequestService;

/** @var \SdpPortal\Component\ServiceDeskPlus\Site\View\Requestform\HtmlView $this */

$limits = AttachmentValidator::limitsForMessages();
?>
<div class="com-servicedeskplus sdp-requestform" data-sdp-root="requestform">
    <a class="sdp-back" href="<?php echo Route::_('index.php?option=com_servicedeskplus&view=requests'); ?>"><span class="sdp-arrow" aria-hidden="true">&larr;</span> <?php echo Text::_('COM_SERVICEDESKPLUS_BACK_TO_REQUESTS'); ?></a>

    <?php if ($this->showPageHeading) : ?>
        <div class="sdp-head">
            <h1 class="h2"><?php echo $this->escape($this->pageHeading); ?></h1>
        </div>
    <?php endif; ?>

    <?php if ($this->createdId !== null) :
        $url = Route::_('index.php?option=com_servicedeskplus&view=request&id=' . rawurlencode($this->createdId));
        ?>
        <div class="sdp-alert sdp-tone-green" role="status">
            <?php echo Text::sprintf('COM_SERVICEDESKPLUS_REQUEST_CREATED', $this->escape($this->createdId)); ?>
            <a href="<?php echo $url; ?>"><?php echo Text::_('COM_SERVICEDESKPLUS_VIEW_REQUEST'); ?> <span class="sdp-arrow" aria-hidden="true">&rarr;</span></a>
        </div>
    <?php endif; ?>

    <section class="sdp-panel">
        <form class="sdp-panel-body" action="<?php echo Route::_('index.php?option=com_servicedeskplus'); ?>" method="post" enctype="multipart/form-data" data-sdp-submit-once>
            <div class="sdp-form-field">
                <label class="sdp-label" for="sdp-subject"><?php echo Text::_('COM_SERVICEDESKPLUS_SUBJECT'); ?> <span aria-hidden="true">*</span></label>
                <input class="sdp-input" type="text" id="sdp-subject" name="subject" required
                       maxlength="<?php echo RequestService::SUBJECT_MAX; ?>" autocomplete="off"
                       value="<?php echo $this->escape($this->input['subject']); ?>">
            </div>
            <div class="sdp-form-field">
                <label class="sdp-label" for="sdp-description"><?php echo Text::_('COM_SERVICEDESKPLUS_DESCRIPTION'); ?> <span aria-hidden="true">*</span></label>
                <textarea class="sdp-input" id="sdp-description" name="description" rows="8" required
                          maxlength="<?php echo RequestService::DESCRIPTION_MAX; ?>"><?php echo $this->escape($this->input['description']); ?></textarea>
            </div>
            <div class="sdp-form-field">
                <label class="sdp-label" for="sdp-attachments"><?php echo Text::_('COM_SERVICEDESKPLUS_ATTACHMENTS'); ?></label>
                <input class="sdp-input" type="file" id="sdp-attachments" name="attachments[]" multiple
                       accept=".jpg,.jpeg,.png,.pdf,.xlsx,.docx,image/jpeg,image/png,application/pdf,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                       data-sdp-max-files="<?php echo AttachmentValidator::MAX_FILES; ?>"
                       data-sdp-max-bytes="<?php echo AttachmentValidator::MAX_FILE_BYTES; ?>"
                       data-sdp-max-total="<?php echo AttachmentValidator::MAX_TOTAL; ?>"
                       data-sdp-extensions="jpg,jpeg,png,pdf,xlsx,docx"
                       data-sdp-error-text="<?php echo $this->escape(Text::sprintf('COM_SERVICEDESKPLUS_ATTACH_CLIENT_ERROR', ...$limits)); ?>"
                       aria-describedby="sdp-attachments-help">
                <p class="sdp-help" id="sdp-attachments-help">
                    <?php echo Text::sprintf('COM_SERVICEDESKPLUS_ATTACHMENTS_HELP', ...$limits); ?>
                </p>
            </div>

            <div class="sdp-actions">
                <button class="sdp-btn" type="submit">
                    <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true" data-sdp-spinner></span>
                    <span data-sdp-label data-sdp-busy-text="<?php echo $this->escape(Text::_('COM_SERVICEDESKPLUS_SUBMITTING')); ?>"><?php echo Text::_('COM_SERVICEDESKPLUS_SUBMIT'); ?></span>
                </button>
                <a class="sdp-btn sdp-btn--ghost" href="<?php echo Route::_('index.php?option=com_servicedeskplus&view=requests'); ?>"><?php echo Text::_('JCANCEL'); ?></a>
            </div>

            <input type="hidden" name="task" value="request.create">
            <input type="hidden" name="submission" value="<?php echo $this->escape($this->submissionToken); ?>">
            <?php echo HTMLHelper::_('form.token'); ?>
        </form>
    </section>
</div>
