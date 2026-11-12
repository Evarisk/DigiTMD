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
 * \file    ajax/tmd.php
 * \ingroup dolitmd
 * \brief   Ticks done and invoiced of a TMD request, saved from the list or the card (js/modules/tmd.js)
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
require_once __DIR__ . '/../class/tmd.class.php';
require_once __DIR__ . '/../lib/dolitmd.lib.php';

// Global variables definitions
global $db, $langs, $user;

saturne_load_langs();

top_httphead('application/json');

// Anything printed on the way (a warning of a trigger of the ticket closed...) would break the JSON: kept aside
ob_start();

/**
 * Answer in JSON and stop
 *
 * @param  int   $httpCode HTTP code
 * @param  array $data     Answer
 * @return void
 */
function dolitmd_ajax_reply(int $httpCode, array $data): void
{
    global $db;

    while (ob_get_level() > 0) {
        $printed = ob_get_clean();
        if (trim((string) $printed) !== '') {
            dol_syslog('dolitmd ajax/tmd.php: output dropped: ' . dol_trunc(strip_tags((string) $printed), 500), LOG_WARNING);
        }
    }
    http_response_code($httpCode);
    print json_encode($data);
    $db->close();
    exit;
}

// Writing only: a POST (the token is checked by main.inc.php), by a user allowed to modify the TMD requests
$action = GETPOST('action', 'aZ09');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($action, ['set_flag', 'set_state'], true)) {
    dolitmd_ajax_reply(400, ['success' => false, 'error' => 'BadRequest']);
}
if (!empty($user->socid) || !$user->hasRight('dolitmd', 'tmd', 'write')) {
    dolitmd_ajax_reply(403, ['success' => false, 'error' => $langs->transnoentities('NotEnoughPermissions')]);
}

$id = GETPOSTINT('id');

$object = new Tmd($db);
if ($id <= 0 || $object->fetch($id) <= 0 || (int) $object->status === Tmd::STATUS_DELETED || !in_array((int) $object->entity, array_map('intval', explode(',', getEntity($object->element))), true)) {
    dolitmd_ajax_reply(404, ['success' => false, 'error' => $langs->transnoentities('ErrorRecordNotFound')]);
}

if ($action == 'set_state') {
    // The state chosen in the list: the pictos follow
    $state = GETPOSTINT('state');
    if (!array_key_exists($state, Tmd::STATE_LABELS)) {
        dolitmd_ajax_reply(400, ['success' => false, 'error' => 'BadState']);
    }
    $result = $object->setState($user, $state);
} else {
    // A picto clicked: done or invoiced, ticked or not
    $field = GETPOST('field', 'aZ09');
    if (!in_array($field, ['done', 'invoiced'], true)) {
        dolitmd_ajax_reply(400, ['success' => false, 'error' => 'BadField']);
    }
    $result = $object->setFlag($user, $field, GETPOSTINT('value') ? 1 : 0);
}
if ($result <= 0) {
    dolitmd_ajax_reply(500, ['success' => false, 'error' => $object->error ?: $langs->transnoentities('TmdFlagError')]);
}

$object->fetch($id);
$counts = $object->countByStatus();
dolitmd_ajax_reply(200, [
    'counts'   => [
        'late'     => $object->countLate(),
        'pending'  => $counts[Tmd::STATUS_PENDING],
        'done'     => $counts[Tmd::STATUS_DONE],
        'invoiced' => $counts[Tmd::STATUS_INVOICED],
        'total'    => array_sum($counts),
    ],
    'success'  => true,
    'id'       => (int) $object->id,
    'done'     => (int) $object->done,
    'invoiced' => (int) $object->invoiced,
    'status'   => $object->getLibStatut(5),
    'state'    => dolitmd_state_html($object, true),
    'flags'    => [
        'done'     => dolitmd_flag_html($object, 'done', true),
        'invoiced' => dolitmd_flag_html($object, 'invoiced', true),
    ],
    'invoice'  => dolitmd_invoice_html($object),
    // Deadline: done, a late request is late no more
    'due'      => dolitmd_due_html($object),
    'message'  => $langs->transnoentities('TmdFlagSaved', $object->ref, dol_string_nohtmltag($object->getLibStatut(1))),
]);
