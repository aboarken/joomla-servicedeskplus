<?php

/**
 * Previous / "Page n of m" / Next links under a portal list (same as My Requests).
 *
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var array{page: int, pages: int, url: callable(int): string} $displayData */
$page  = (int) ($displayData['page'] ?? 1);
$pages = (int) ($displayData['pages'] ?? 1);
$url   = $displayData['url'];

if ($pages <= 1) {
    return;
}
?>
<nav aria-label="<?php echo htmlspecialchars(Text::_('JLIB_HTML_PAGINATION'), ENT_QUOTES, 'UTF-8'); ?>">
    <ul class="pagination">
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
            <?php if ($page > 1) : ?>
                <a class="page-link" href="<?php echo $url($page - 1); ?>"><span class="sdp-arrow" aria-hidden="true">&larr;</span> <?php echo Text::_('JPREV'); ?></a>
            <?php else : ?>
                <span class="page-link" aria-disabled="true"><span class="sdp-arrow" aria-hidden="true">&larr;</span> <?php echo Text::_('JPREV'); ?></span>
            <?php endif; ?>
        </li>
        <li class="page-item disabled">
            <span class="page-link"><?php echo Text::sprintf('COM_SERVICEDESKPLUS_PAGE_N_OF_M', $page, $pages); ?></span>
        </li>
        <li class="page-item <?php echo $page < $pages ? '' : 'disabled'; ?>">
            <?php if ($page < $pages) : ?>
                <a class="page-link" href="<?php echo $url($page + 1); ?>"><?php echo Text::_('JNEXT'); ?> <span class="sdp-arrow" aria-hidden="true">&rarr;</span></a>
            <?php else : ?>
                <span class="page-link" aria-disabled="true"><?php echo Text::_('JNEXT'); ?> <span class="sdp-arrow" aria-hidden="true">&rarr;</span></span>
            <?php endif; ?>
        </li>
    </ul>
</nav>
