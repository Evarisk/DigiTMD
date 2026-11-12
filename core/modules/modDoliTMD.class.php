<?php
/* Copyright (C) 2023-2026 EVARISK <technique@evarisk.com>
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
 * \defgroup dolitmd Module DoliTMD
 * \brief    DoliTMD module descriptor.
 *
 * \file    core/modules/modDoliTMD.class.php
 * \ingroup dolitmd
 * \brief   Description and activation file for module DoliTMD
 */

// Load Dolibarr libraries
require_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module DoliTMD
 */
class modDoliTMD extends DolibarrModules
{
    /**
     * @var int[] Minimum version of Saturne required by module, same format as need_dolibarr_version: checked by init() and by the Saturne index page
     */
    public $need_saturne_version = [23, 7];

    /**
     * Constructor. Define names, constants, directories, boxes, permissions
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $conf, $langs;

        $this->db = $db;

        if (file_exists(dol_buildpath('/saturne/lib/saturne_functions.lib.php'))) {
            dol_include_once('/saturne/lib/saturne_functions.lib.php');
            saturne_load_langs(['dolitmd@dolitmd']);
        } else {
            $this->error++;
            $this->errors[] = $langs->trans('activateModuleDependNotSatisfied', 'DoliTMD', 'Saturne');
        }

        $this->numero          = 436303;
        $this->rights_class    = 'dolitmd';
        $this->family          = '';
        $this->module_position = '';
        $this->familyinfo      = ['Evarisk' => ['position' => '01', 'label' => 'Evarisk']];
        $this->name            = preg_replace('/^mod/i', '', get_class($this));
        $this->description     = $langs->trans('DoliTMDDescription');
        $this->descriptionlong = $langs->trans('DoliTMDDescriptionLong');
        $this->editor_name     = 'Evarisk';
        $this->editor_url      = 'https://www.evarisk.com';
        // Same numbering as the other modules of Evarisk: the major one is the version of Dolibarr it is made for
        $this->version         = '23.0.0';
        $this->const_name      = 'MAIN_MODULE_' . strtoupper($this->name);
        $this->picto           = 'dolitmd_color@dolitmd';

        $this->module_parts = [
            'triggers'          => 1,
            'login'             => 0,
            'substitutions'     => 0,
            'menus'             => 0,
            'tpl'               => 0,
            'barcode'           => 0,
            'models'            => 0,
            'printing'          => 0,
            'theme'             => 0,
            'css'               => [],
            'js'                => [],
            // saturnegetobjectsmetadata: the TMD requests in the generic list of Saturne ; elementproperties: the
            // inline edit of the list finds the class ; tmdlist_saturne: the columns, cards and script of that list
            // emailtemplates: the type « TMD requests » in Setup > Emails > Templates
            'hooks'             => [
                'saturnegetobjectsmetadata',
                'elementproperties',
                'tmdlist_saturne',
                'emailtemplates',
            ],
            'moduleforexternal' => 0,
        ];

        $this->dirs             = ['/dolitmd/temp'];
        $this->config_page_url  = ['setup.php@dolitmd'];
        $this->hidden           = false;
        $this->depends          = ['modSaturne', 'modSociete', 'modTicket'];
        $this->requiredby       = [];
        $this->conflictwith     = [];
        $this->langfiles        = ['dolitmd@dolitmd'];
        $this->phpmin           = [7, 4];
        $this->need_dolibarr_version   = [23, 0];
        $this->warnings_activation     = [];
        $this->warnings_activation_ext = [];

        $i = 0;
        $this->const = [
            // CONST MODULE
            $i++ => ['DOLITMD_VERSION', 'chaine', $this->version, '', 0, 'current'],
            $i++ => ['DOLITMD_DB_VERSION', 'chaine', $this->version, '', 0, 'current'],
            // CONST TMD
            $i++ => ['DOLITMD_TMD_ADDON', 'chaine', 'mod_tmd_standard', '', 0, 'current'],
            // Delay to handle a request, in days (0 = no deadline); the automatic mails stay off until switched on
            $i   => ['DOLITMD_DELAY_DAYS', 'chaine', '15', '', 0, 'current'],
        ];

        if (!isset($conf->dolitmd) || !isset($conf->dolitmd->enabled)) {
            $conf->dolitmd          = new stdClass();
            $conf->dolitmd->enabled = 0;
        }

        $this->tabs         = [];
        $this->dictionaries = [];
        $this->boxes        = [];
        // Mails of the TMD mailbox: fetched, then a ticket and a request for each new one (the trigger INBOX_MAIL_RECEIVED
        // is switched off until Inbox sends it, see the triggers of the module)
        $this->cronjobs = [
            0 => [
                'label'         => 'TmdCronCatchUp',
                'jobtype'       => 'method',
                'class'         => '/dolitmd/class/tmdmailhandler.class.php',
                'objectname'    => 'TmdMailHandler',
                'method'        => 'cronCatchUp',
                'parameters'    => '',
                'comment'       => 'TmdCronCatchUpComment',
                'frequency'     => 5,
                'unitfrequency' => 60,
                'status'        => 1,
                'test'          => 'isModEnabled("dolitmd") && isModEnabled("inbox")',
                'priority'      => 50,
            ],
            // Every morning: the late requests to the person assigned (only when switched on in the setup)
            1 => [
                'label'         => 'TmdCronLate',
                'jobtype'       => 'method',
                'class'         => '/dolitmd/class/tmdmailer.class.php',
                'objectname'    => 'TmdMailer',
                'method'        => 'cronLateReminder',
                'parameters'    => '',
                'comment'       => 'TmdCronLateComment',
                'frequency'     => 1,
                'unitfrequency' => 86400,
                'datestart'     => strtotime('tomorrow 08:00'),
                'status'        => 1,
                'test'          => 'isModEnabled("dolitmd")',
                'priority'      => 60,
            ],
        ];

        // Permissions provided by this module
        $this->rights = [];
        $r            = 0;

        /* DOLITMD PERMISSIONS */
        $this->rights[$r][0] = $this->numero . sprintf('%02d', $r + 1);
        $this->rights[$r][1] = $langs->trans('LireModule', 'DoliTMD');
        $this->rights[$r][4] = 'lire';
        $this->rights[$r][5] = 1;
        $r++;
        $this->rights[$r][0] = $this->numero . sprintf('%02d', $r + 1);
        $this->rights[$r][1] = $langs->trans('ReadModule', 'DoliTMD');
        $this->rights[$r][4] = 'read';
        $this->rights[$r][5] = 1;
        $r++;

        /* TMD PERMISSIONS */
        $this->rights[$r][0] = $this->numero . sprintf('%02d', $r + 1);
        $this->rights[$r][1] = $langs->transnoentities('ReadObjects', $langs->transnoentities('TmdRequests'));
        $this->rights[$r][4] = 'tmd';
        $this->rights[$r][5] = 'read';
        $r++;
        $this->rights[$r][0] = $this->numero . sprintf('%02d', $r + 1);
        $this->rights[$r][1] = $langs->transnoentities('CreateObjects', $langs->transnoentities('TmdRequests'));
        $this->rights[$r][4] = 'tmd';
        $this->rights[$r][5] = 'write';
        $r++;
        $this->rights[$r][0] = $this->numero . sprintf('%02d', $r + 1);
        $this->rights[$r][1] = $langs->transnoentities('DeleteObjects', $langs->transnoentities('TmdRequests'));
        $this->rights[$r][4] = 'tmd';
        $this->rights[$r][5] = 'delete';
        $r++;

        /* ADMINPAGE PANEL ACCESS PERMISSIONS */
        $this->rights[$r][0] = $this->numero . sprintf('%02d', $r + 1);
        $this->rights[$r][1] = $langs->transnoentities('ReadAdminPage', 'DoliTMD');
        $this->rights[$r][4] = 'adminpage';
        $this->rights[$r][5] = 'read';

        // Main menu entries to add
        $this->menu = [];
        $r          = 0;

        $this->menu[$r++] = [
            'fk_menu'  => '',
            'type'     => 'top',
            'titre'    => 'DoliTMD',
            'prefix'   => '<i class="fas fa-truck-moving pictofixedwidth"></i>',
            'mainmenu' => 'dolitmd',
            'leftmenu' => '',
            'url'      => '/saturne/view/saturne_list.php?object_type=tmd',
            'langs'    => 'dolitmd@dolitmd',
            'position' => 1000 + $r,
            'enabled'  => 'isModEnabled("dolitmd")',
            'perms'    => '$user->hasRight("dolitmd", "tmd", "read")',
            'target'   => '',
            'user'     => 0,
        ];
        $this->menu[$r++] = [
            'fk_menu'  => 'fk_mainmenu=dolitmd',
            'type'     => 'left',
            'titre'    => 'TmdList',
            'prefix'   => '<i class="fas fa-truck-moving pictofixedwidth"></i>',
            'mainmenu' => 'dolitmd',
            'leftmenu' => 'tmd',
            'url'      => '/saturne/view/saturne_list.php?object_type=tmd',
            'langs'    => 'dolitmd@dolitmd',
            'position' => 1000 + $r,
            'enabled'  => 'isModEnabled("dolitmd")',
            'perms'    => '$user->hasRight("dolitmd", "tmd", "read")',
            'target'   => '',
            'user'     => 0,
        ];
        $this->menu[$r++] = [
            'fk_menu'  => 'fk_mainmenu=dolitmd,fk_leftmenu=tmd',
            'type'     => 'left',
            'titre'    => 'NewTmd',
            'mainmenu' => 'dolitmd',
            'leftmenu' => 'tmd_new',
            'url'      => '/dolitmd/view/tmd/tmd_card.php?action=create',
            'langs'    => 'dolitmd@dolitmd',
            'position' => 1000 + $r,
            'enabled'  => 'isModEnabled("dolitmd")',
            'perms'    => '$user->hasRight("dolitmd", "tmd", "write")',
            'target'   => '',
            'user'     => 0,
        ];
    }

    /**
     * Function called when module is enabled.
     * The init function adds tabs, constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
     * It also creates data directories
     *
     * @param  string $options Options when enabling module ('', 'noboxes')
     * @return int             1 if OK, 0 if KO
     */
    public function init($options = ''): int
    {
        global $conf, $langs;

        // Saturne ships separately: refuse one older than need_saturne_version, it lacks functions this release calls
        require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
        dol_include_once('/saturne/core/modules/modSaturne.class.php');
        $saturneVersion = class_exists('modSaturne') ? (new modSaturne($this->db))->version : '';
        if (versioncompare(explode('.', $saturneVersion), $this->need_saturne_version) < 0) {
            $langs->load('admin'); // activateModuleDependNotSatisfied, not loaded when the Saturne index page runs init()
            $this->error = $langs->trans('activateModuleDependNotSatisfied', $this->name, 'modSaturne >= ' . versiontostring($this->need_saturne_version) . ' (' . ($saturneVersion ?: '-') . ')', $this->name);
            return -1;
        }

        $result = $this->_load_tables('/dolitmd/sql/');
        if ($result < 0) {
            return -1;
        }

        dolibarr_set_const($this->db, 'DOLITMD_VERSION', $this->version, 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($this->db, 'DOLITMD_DB_VERSION', $this->version, 'chaine', 0, '', $conf->entity);

        // Permissions
        $this->remove($options);

        $sql = [];

        return $this->_init($sql, $options);
    }

    /**
     * Function called when module is disabled.
     * Remove from database constants, boxes and permissions from Dolibarr database.
     * Data directories are not deleted
     *
     * @param  string $options Options when enabling module ('', 'noboxes')
     * @return int             1 if OK, 0 if KO
     */
    public function remove($options = ''): int
    {
        $sql = [];

        return $this->_remove($sql, $options);
    }
}
