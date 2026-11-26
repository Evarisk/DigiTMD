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
 * \file    ajax/inbox.php
 * \ingroup dolitmd
 * \brief   Block « TMD » of the panel of a mail of Inbox (hook inboxMailPanels): the request of the mail, in JSON
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
if (!defined('NOREQUIREAJAX')) {
    define('NOREQUIREAJAX', 1);
}

// Load DoliTMD environment
if (file_exists('../dolitmd.main.inc.php')) {
    require_once __DIR__ . '/../dolitmd.main.inc.php';
} elseif (file_exists('../../dolitmd.main.inc.php')) {
    require_once __DIR__ . '/../../dolitmd.main.inc.php';
} else {
    die('Include of dolitmd main fails');
}

// Load DoliTMD libraries
require_once __DIR__ . '/../class/tmdinbox.class.php';

// Global variables definitions
global $db, $langs, $user;

saturne_load_langs();

top_httphead('application/json');

// A warning printed on the way would break the JSON
ob_start();

// Refused: an error in the JSON (shown by the block; Inbox shows only the HTTP status of an answer in error)
$data = ['error' => $langs->transnoentities('TmdInboxMailNotFound')];
if (!$user->hasRight('dolitmd', 'tmd', 'read')) {
    $data = ['error' => $langs->transnoentities('NotEnoughPermissions')];
} else {
    // The hook runs for any mail the user opens: the mail is read again with his rights in Inbox
    $inbox = new TmdInbox($db);
    $row   = $inbox->mailRow(GETPOSTINT('mail'), $user);
    if ($row !== null) {
        $data = $inbox->summary($row, $user);
    }
}

$printed = ob_get_clean();
if (trim((string) $printed) !== '') {
    dol_syslog('dolitmd ajax/inbox.php: output dropped: ' . dol_trunc(strip_tags((string) $printed), 500), LOG_WARNING);
}
print json_encode($data);
$db->close();
