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
 * \file    core/modules/dolitmd/tmd/mod_tmd_standard.php
 * \ingroup dolitmd
 * \brief   File containing class for numbering module Standard of the TMD requests
 */

// Load Saturne libraries
dol_include_once('/saturne/core/modules/saturne/modules_saturne.php');

/**
 * Class of numbering module Standard for the TMD requests
 */
class mod_tmd_standard extends ModeleNumRefSaturne
{
    /**
     * @var string Model prefix
     */
    public string $prefix = 'TMD';

    /**
     * @var string Model name
     */
    public string $name = 'Adr';
}
