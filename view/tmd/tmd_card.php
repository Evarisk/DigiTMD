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
 * \file    view/tmd/tmd_card.php
 * \ingroup dolitmd
 * \brief   Card of a TMD request: controller, the screens are in core/tpl/dolitmd_tmd_card_*.tpl.php
 */

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
require_once __DIR__ . '/../../lib/dolitmd.lib.php';
require_once __DIR__ . '/../../lib/dolitmd_tmd.lib.php';

// Global variables definitions
global $conf, $db, $hookmanager, $langs, $user;

// Load translation files required by the page
saturne_load_langs(['ticket']);

// Get parameters
$id                  = GETPOSTINT('id');
$ref                 = GETPOST('ref', 'alpha');
$action              = GETPOST('action', 'aZ09');
$confirm             = GETPOST('confirm', 'alpha');
$cancel              = GETPOST('cancel', 'aZ09');
$backtopage          = GETPOST('backtopage', 'alpha');
$backtopageforcancel = GETPOST('backtopageforcancel', 'alpha');

// Initialize technical objects
$object      = new Tmd($db);
$extrafields = new ExtraFields($db);
$form        = new Form($db);

$hookmanager->initHooks(['tmdcard', 'globalcard']);

if (empty($action) && empty($id) && empty($ref)) {
    $action = 'view';
}

include DOL_DOCUMENT_ROOT . '/core/actions_fetchobject.inc.php'; // Must be included, not include_once

// A deleted request is never shown
if ($object->id > 0 && (int) $object->status === Tmd::STATUS_DELETED) {
    accessforbidden($langs->trans('ErrorRecordNotFound'));
}

// Security check
$permissiontoread   = $user->hasRight('dolitmd', 'tmd', 'read');
$permissiontoadd    = $user->hasRight('dolitmd', 'tmd', 'write');
$permissiontodelete = $user->hasRight('dolitmd', 'tmd', 'delete');
saturne_check_access($permissiontoread, $object);

/*
 * Actions
 */

$parameters = ['id' => $id];
$resHook    = $hookmanager->executeHooks('doActions', $parameters, $object, $action);
if ($resHook < 0) {
    setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($resHook)) {
    $listUrl = dol_buildpath('/saturne/view/saturne_list.php', 1) . '?object_type=tmd&mainmenu=dolitmd&leftmenu=tmd';
    if (empty($backtopage) || ($cancel && empty($id))) {
        if (empty($id) && (($action != 'add' && $action != 'create') || $cancel)) {
            $backtopage = $listUrl;
        } else {
            $backtopage = dol_buildpath('/dolitmd/view/tmd/tmd_card.php', 1) . '?id=' . ($id > 0 ? $id : '__ID__');
        }
    }
    $backurlforlist = $listUrl;
    $triggermodname = 'TMD_MODIFY';

    require_once DOL_DOCUMENT_ROOT . '/core/actions_addupdatedelete.inc.php';
}

/*
 * View
 */

$title = $langs->trans('Tmd');
saturne_header(0, '', $title, '', '', 0, 0, [], [], '', 'mod-tmd page-card');

// One screen per state of the page
if ($action == 'create') {
    $showScreen = 'create';
} elseif (($id || $ref) && $action == 'edit') {
    $showScreen = 'edit';
} elseif ($object->id > 0) {
    $showScreen = 'view';
} else {
    $showScreen = '';
}

$screenFile = __DIR__ . '/../../core/tpl/dolitmd_tmd_card_' . $showScreen . '.tpl.php';
if ($showScreen !== '' && is_readable($screenFile)) {
    include $screenFile;
}

// End of page
llxFooter();
$db->close();
