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
 * \file    view/tmd/tmd_stats.php
 * \ingroup dolitmd
 * \brief   Statistics of the TMD requests: controller, the screen is core/tpl/dolitmd_tmd_stats.tpl.php
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
require_once __DIR__ . '/../../class/tmdstats.class.php';

// Global variables definitions
global $db, $hookmanager, $langs, $user;

// Load translation files required by the page
saturne_load_langs();

// Get parameters
$period = GETPOST('period', 'aZ09');

$hookmanager->initHooks(['tmdstats', 'globalcard']);

// Security check
saturne_check_access($user->hasRight('dolitmd', 'tmd', 'read'));

/*
 * View
 */

$stats = (new TmdStats($db))->load((string) $period);

$title   = $langs->trans('TmdStats');
$helpUrl = 'FR:Module_DoliTMD';

saturne_header(0, '', $title, $helpUrl);

$listUrl       = dol_buildpath('/saturne/view/saturne_list.php', 1) . '?object_type=tmd&mainmenu=dolitmd&leftmenu=tmd';
$morehtmlright = '<a class="butAction" href="' . dol_escape_htmltag($listUrl) . '"><i class="fas fa-list pictofixedwidth"></i>' . $langs->trans('TmdList') . '</a>';

print load_fiche_titre($title, $morehtmlright, 'fontawesome_fa-chart-bar_fas_#E67E22');

include __DIR__ . '/../../core/tpl/dolitmd_tmd_stats.tpl.php';

// End of page
llxFooter();
$db->close();
