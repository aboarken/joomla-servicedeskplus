<?php

/**
 * @license GNU General Public License version 2 or later
 *
 * Unit tests run the component's own classes without Joomla. Those classes refuse to load outside
 * Joomla (defined('_JEXEC') or die), so the constant is set here, for tests only.
 */

\define('_JEXEC', 1);

require \dirname(__DIR__) . '/vendor/autoload.php';
