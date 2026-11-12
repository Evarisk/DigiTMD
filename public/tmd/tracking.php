<?php
/* Copyright (C) 2026 EVARISK <technique@evarisk.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    public/tmd/tracking.php
 * \ingroup dolitmd
 * \brief   Public page of a TMD request, for its sender: where it stands and its documents. Opened by its tracking
 *          number (link of the mails, or typed in the form of the page); the screens are in
 *          core/tpl/dolitmd_tmd_public_tracking.tpl.php
 */

if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', 1);
}
if (!defined('NOREQUIREMENU')) {
    define('NOREQUIREMENU', 1);
}
if (!defined('NOREQUIREHTML')) {
    define('NOREQUIREHTML', 1);
}
if (!defined('NOLOGIN')) { // This page does not require to be logged: the tracking number is its only key
    define('NOLOGIN', 1);
}
if (!defined('NOCSRFCHECK')) { // Opened from a mail
    define('NOCSRFCHECK', 1);
}
if (!defined('NOIPCHECK')) {
    define('NOIPCHECK', 1);
}
if (!defined('NOBROWSERNOTIF')) {
    define('NOBROWSERNOTIF', 1);
}

// Entity of the request, before Dolibarr loads its setup
$entity = (int) ($_GET['entity'] ?? ($_POST['entity'] ?? 1));
if ($entity > 0 && !defined('DOLENTITY')) {
    define('DOLENTITY', $entity);
}

// Load DoliTMD environment
if (file_exists('../../dolitmd.main.inc.php')) {
    require_once __DIR__ . '/../../dolitmd.main.inc.php';
} elseif (file_exists('../../../dolitmd.main.inc.php')) {
    require_once __DIR__ . '/../../../dolitmd.main.inc.php';
} else {
    die('Include of dolitmd main fails');
}

// Load DoliTMD libraries
require_once __DIR__ . '/../../class/tmd.class.php';
require_once __DIR__ . '/../../class/tmdmailhandler.class.php';

// Global variables definitions
global $conf, $db, $hookmanager, $langs, $mysoc;

// Load translation files required by the page
saturne_load_langs();

// The module is off in this entity: no page
if (!isModEnabled('dolitmd')) {
    httponly_accessforbidden('Module DoliTMD not enabled', 404);
}

// Get parameters
$trackId = trim(GETPOST('track_id', 'alphanohtml'));

// Initialize technical objects
$object = new Tmd($db);

$hookmanager->initHooks(['tmdpublictracking', 'saturnepublicinterface']);

// One answer for a number unknown, mistyped or of a deleted request: nothing tells them apart
$found = $trackId !== '' && $object->fetchByTrackId($trackId) > 0;
$error = $trackId !== '' && !$found;

$ticketRef = '';
$files     = [];
$contact   = '';
if ($found) {
    if ($object->fk_ticket > 0 && isModEnabled('ticket')) {
        require_once DOL_DOCUMENT_ROOT . '/ticket/class/ticket.class.php';
        $ticket = new Ticket($db);
        if ($ticket->fetch((int) $object->fk_ticket) > 0) {
            $ticketRef = (string) $ticket->ref;
        }
    }
    $files = $object->getPublicFiles();

    // Questions go to the TMD mailbox, with the reference in the subject: the answer joins the ticket of the request
    $mailbox = TmdMailHandler::configuredMailbox($db);
    $contact = $mailbox !== null && isValidEmail((string) $mailbox->email) ? (string) $mailbox->email : '';
}

/*
 * View
 */

// The address carries the tracking number: kept out of the Referer of the other sites, never indexed
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');

$title = $found ? $langs->trans('TmdPublicTitleOf', $ticketRef ?: $object->ref) : $langs->trans('TmdPublicTitle');

$conf->dol_hide_topmenu  = 1;
$conf->dol_hide_leftmenu = 1;

saturne_header(0, '', $title, '', '', 0, 0, [], [], '', 'page-public-card page-public-tmd');

include __DIR__ . '/../../core/tpl/dolitmd_tmd_public_tracking.tpl.php';

llxFooter('', 'public');
$db->close();
