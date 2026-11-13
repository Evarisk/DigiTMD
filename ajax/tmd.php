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
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($action, ['set_flag', 'set_state', 'create_thirdparty'], true)) {
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

if ($action == 'create_thirdparty') {
    // Thirdparty of an unknown sender (modal of the list and of the card): created with the address, or the one chosen
    // when the name is taken already; then on the requests of the address and on their tickets
    require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
    require_once __DIR__ . '/../class/tmdmailhandler.class.php';

    global $mysoc;

    $langs->loadLangs(['errors', 'companies']);

    if (!empty($object->fk_soc)) {
        dolitmd_ajax_reply(409, ['success' => false, 'error' => $langs->transnoentities('TmdSenderAlreadyLinked')]);
    }
    $email = strtolower(trim((string) $object->email_from));
    if (!isValidEmail($email)) {
        dolitmd_ajax_reply(400, ['success' => false, 'error' => $langs->transnoentities('ErrorBadEMail', $email)]);
    }

    $thirdparty = new Societe($db);
    $socId      = GETPOSTINT('socid');
    $contactId  = 0;
    $created    = false;
    if ($socId > 0) {
        // The thirdparty of the same name, chosen in the modal
        if (!$user->hasRight('societe', 'lire') || $thirdparty->fetch($socId) <= 0 || !in_array((int) $thirdparty->entity, array_map('intval', explode(',', getEntity('societe'))), true)) {
            dolitmd_ajax_reply(404, ['success' => false, 'error' => $langs->transnoentities('ErrorRecordNotFound')]);
        }
    } else {
        if (!dolitmd_can_create_thirdparty($user)) {
            dolitmd_ajax_reply(403, ['success' => false, 'error' => $langs->transnoentities('NotEnoughPermissions')]);
        }
        // The address got a thirdparty or a contact since the mail came: that one, never a second
        [$socId, $contactId] = (new TmdMailHandler($db))->findSender($email);
        if ($socId <= 0) {
            $name = dol_substr(trim(GETPOST('name', 'alphanohtml')), 0, 128);
            if ($name === '') {
                dolitmd_ajax_reply(400, ['success' => false, 'error' => $langs->transnoentities('ErrorFieldRequired', $langs->transnoentities('ThirdPartyName'))]);
            }
            // The fields of the modal, read as societe/card.php reads them
            $socEmail = trim(GETPOST('email', 'custom', 0, FILTER_SANITIZE_EMAIL));
            if ($socEmail !== '' && !isValidEmail($socEmail)) {
                dolitmd_ajax_reply(400, ['success' => false, 'error' => $langs->transnoentities('ErrorBadEMail', $socEmail)]);
            }
            $socClient = GETPOSTISSET('client') ? GETPOSTINT('client') : 1;
            // A thirdparty has this name already: the modal offers it instead of a duplicate
            $same = $db->getRow('SELECT rowid FROM ' . $db->prefix() . 'societe WHERE entity IN (' . getEntity('societe') . ") AND LOWER(nom) = '" . $db->escape(dol_strtolower($name)) . "' ORDER BY status DESC, rowid ASC");
            if (is_object($same) && $thirdparty->fetch((int) $same->rowid) > 0) {
                dolitmd_ajax_reply(409, [
                    'success'  => false,
                    'error'    => $langs->transnoentities('TmdThirdpartyNameExists', $thirdparty->name),
                    'existing' => ['id' => (int) $thirdparty->id, 'label' => $langs->transnoentities('TmdLinkToThirdparty', $thirdparty->name)],
                ]);
            }

            $thirdparty->name        = $name;
            $thirdparty->client      = in_array($socClient, [0, 1, 2, 3], true) ? $socClient : 1;
            $thirdparty->code_client = $thirdparty->client > 0 ? -1 : '';
            $thirdparty->status      = 1;
            $thirdparty->address     = GETPOST('address', 'alphanohtml');
            $thirdparty->zip         = GETPOST('zipcode', 'alphanohtml');
            $thirdparty->town        = GETPOST('town', 'alphanohtml');
            $thirdparty->country_id  = GETPOSTINT('country_id') > 0 ? GETPOSTINT('country_id') : (int) $mysoc->country_id;
            $thirdparty->email       = $socEmail;
            $thirdparty->phone       = GETPOST('phone', 'alpha');
            $thirdparty->tva_intra   = GETPOST('tva_intra', 'alphanohtml');
            for ($i = 1; $i <= 6; $i++) {
                if (GETPOSTISSET('idprof' . $i)) {
                    $thirdparty->{'idprof' . $i} = GETPOST('idprof' . $i, 'alphanohtml');
                }
            }
            if ($thirdparty->create($user) <= 0) {
                dolitmd_ajax_reply(500, ['success' => false, 'error' => trim($thirdparty->error . ' ' . implode(' ', (array) $thirdparty->errors)) ?: $langs->transnoentities('TmdThirdpartyError')]);
            }
            $socId   = (int) $thirdparty->id;
            $created = true;

            // Tags of the customers and prospects, as societe/card.php sets them once the thirdparty is created
            $socTags = GETPOST('custcats', 'array:int');
            if (!empty($socTags) && isModEnabled('category') && $user->hasRight('categorie', 'lire') && $thirdparty->setCategories($socTags, 'customer') < 0) {
                dol_syslog('dolitmd ajax/tmd.php: tags of thirdparty ' . $socId . ' not set: ' . $thirdparty->error, LOG_WARNING);
            }
        } elseif ($thirdparty->fetch($socId) <= 0) {
            dolitmd_ajax_reply(404, ['success' => false, 'error' => $langs->transnoentities('ErrorRecordNotFound')]);
        }
    }

    $ids = $object->linkSenderThirdparty($user, $socId, $contactId);
    if ($ids === false) {
        dolitmd_ajax_reply(500, ['success' => false, 'error' => $object->error ?: $langs->transnoentities('TmdThirdpartyError')]);
    }

    dolitmd_ajax_reply(200, [
        'success'    => true,
        'ids'        => $ids,
        'thirdparty' => $thirdparty->getNomUrl(1),
        'message'    => $langs->transnoentities($created ? 'TmdThirdpartyCreated' : 'TmdThirdpartyLinked', $thirdparty->name, count($ids)),
    ]);
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
