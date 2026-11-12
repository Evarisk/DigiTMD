<?php
/* Copyright (C) 2023 EVARISK <technique@evarisk.com>
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
 *	\file       dolitmdindex.php
 *	\ingroup    dolitmd
 *	\brief      Former home page of DoliTMD: the top menu opens the list of the TMD requests, kept for the bookmarks
 */

// Load dolitmd environment
if (file_exists('dolitmd.main.inc.php')) {
    require_once __DIR__ . '/dolitmd.main.inc.php';
} else {
    die('Include of dolitmd main fails');
}

// Global variables definitions
global $user;

// Security check
saturne_check_access($user->hasRight('dolitmd', 'tmd', 'read'));

header('Location: ' . dol_buildpath('/saturne/view/saturne_list.php', 1) . '?object_type=tmd&mainmenu=dolitmd&leftmenu=tmd');
exit;
