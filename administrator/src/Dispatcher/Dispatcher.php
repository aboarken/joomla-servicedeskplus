<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Administrator\Dispatcher;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Dispatcher\ComponentDispatcher;

/**
 * Administrator dispatcher: every administrator page and task of this component needs
 * "Configure" (core.admin), not only "Access Administration Interface" (core.manage). The
 * controllers check it too; this one place covers anything added later.
 */
class Dispatcher extends ComponentDispatcher
{
    protected function checkAccess()
    {
        parent::checkAccess();

        if (!$this->app->getIdentity()->authorise('core.admin', $this->option)) {
            throw new NotAllowed($this->app->getLanguage()->_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }
}
