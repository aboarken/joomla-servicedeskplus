<?php

/**
 * Summary cards above a list (My Requests, My Approvals, My Loans, Announcements): one card per
 * group with its total; a card is a link that shows that group, the selected one is marked.
 *
 * $displayData['cards']: list of ['label' => string, 'count' => ?int (null: unknown),
 *                         'url' => string, 'tone' => string, 'active' => bool]
 * $displayData['label']: name of the group of cards, for screen readers
 *
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

$cards = $displayData['cards'] ?? [];

if ($cards === []) {
    return;
}
?>
<?php // Up to 8 cards share one row (their number sets the columns); more wrap. ?>
<nav class="sdp-stats sdp-stats--filter<?php echo \count($cards) <= 8 ? ' sdp-stats--one-row sdp-stats--n' . \count($cards) : ''; ?>" aria-label="<?php echo htmlspecialchars((string) ($displayData['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
    <?php foreach ($cards as $card) : ?>
        <a class="sdp-stat sdp-tone-<?php echo htmlspecialchars((string) ($card['tone'] ?? 'blue'), ENT_QUOTES, 'UTF-8'); ?><?php echo !empty($card['active']) ? ' is-active' : ''; ?>"
           href="<?php echo $card['url']; ?>"<?php echo !empty($card['active']) ? ' aria-current="true"' : ''; ?>>
            <span class="sdp-stat-label"><?php echo htmlspecialchars((string) $card['label'], ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="sdp-stat-value"><?php echo isset($card['count']) ? number_format((int) $card['count']) : '—'; ?></span>
        </a>
    <?php endforeach; ?>
</nav>
