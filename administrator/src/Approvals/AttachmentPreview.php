<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Administrator\Approvals;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Which approval attachments may be shown in the browser (the attachment popup) instead of
 * downloaded. Kept out of the site controller, where every public method is a task that can be
 * called from a URL.
 */
final class AttachmentPreview
{
    /**
     * Types that may be shown in the browser: images and PDF only, and only when the content
     * really is of that type (checked when the file is sent). They cannot run as a page of this
     * site; everything else (HTML, SVG, office files, ...) is always a download.
     */
    public const INLINE_TYPES = [
        'pdf'  => 'application/pdf',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];

    /** 'pdf', 'image' or '' (download only) for a file of this name. */
    public static function kind(string $name): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return match (true) {
            $extension === 'pdf'                   => 'pdf',
            isset(self::INLINE_TYPES[$extension]) => 'image',
            default                                => '',
        };
    }
}
