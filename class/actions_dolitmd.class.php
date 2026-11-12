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
 * \file    class/actions_dolitmd.class.php
 * \ingroup dolitmd
 * \brief   DoliTMD hook overload
 */

// Load Dolibarr libraries
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';

// Load DoliTMD libraries
require_once __DIR__ . '/../lib/dolitmd.lib.php';

/**
 * Class ActionsDolitmd
 */
class ActionsDolitmd
{
    /**
     * @var DoliDB Database handler
     */
    public DoliDB $db;

    /**
     * @var string Error code (or message)
     */
    public string $error = '';

    /**
     * @var array Errors
     */
    public array $errors = [];

    /**
     * @var array Hook results. Propagated to $hookmanager->resArray for later reuse (reset to null by the hook manager)
     */
    public $results = [];

    /**
     * @var string|null String displayed by executeHook() immediately after return (reset to null by the hook manager)
     */
    public $resprints = '';

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct(DoliDB $db)
    {
        $this->db = $db;
    }

    /**
     * The TMD requests in the objects known by Saturne: the generic list serves them
     *
     * @param  array $parameters Hook metadata (context, etc...)
     * @return int               0 < on error, 0 on success, 1 to replace standard code
     */
    public function saturneExtendGetObjectsMetadata(array $parameters): int
    {
        $this->results = [];
        if (!isModEnabled('dolitmd')) {
            return 0;
        }

        $this->results['tmd'] = [
            'mainmenu'       => 'dolitmd',
            'leftmenu'       => 'tmd',
            'langs'          => 'Tmd',
            'langfile'       => 'dolitmd@dolitmd',
            'picto'          => 'fontawesome_fa-truck-moving_fas_#E67E22',
            'color'          => '#E67E22',
            'class_name'     => 'Tmd',
            'name_field'     => 'ref',
            'label_field'    => 'label',
            'post_name'      => 'fk_tmd',
            'link_name'      => 'dolitmd_tmd',
            'tab_type'       => 'tmd',
            'table_element'  => 'dolitmd_tmd',
            'hook_name_card' => 'tmdcard',
            'hook_name_list' => 'tmdlist',
            'create_url'     => 'dolitmd/view/tmd/tmd_card.php',
            'list_url'       => 'saturne/view/saturne_list.php?object_type=tmd',
            'defaultsort'    => 't.date_mail',
            'defaultorder'   => 'DESC',
            'class_path'     => 'dolitmd/class/tmd.class.php',
        ];

        return 0;
    }

    /**
     * The class of a TMD request, for the core functions that load an object by its element (inline edit of the list)
     *
     * @param  array $parameters Hook metadata (context, etc...)
     * @return int               0 < on error, 0 on success, 1 to replace standard code
     */
    public function getElementProperties(array $parameters): int
    {
        $this->results = [];
        if (($parameters['elementType'] ?? '') === 'tmd' || ($parameters['elementType'] ?? '') === 'tmd@dolitmd') {
            $this->results = [
                'module'        => 'dolitmd',
                'element'       => 'tmd',
                'table_element' => 'dolitmd_tmd',
                'subelement'    => 'tmd',
                'classpath'     => 'dolitmd/class',
                'classfile'     => 'tmd',
                'classname'     => 'Tmd',
            ];
        }

        return 0;
    }

    /**
     * Script and style of the module on the list of the TMD requests (the generic list of Saturne loads only its own)
     *
     * @param  array $parameters Hook metadata (context, etc...)
     * @return int               0 < on error, 0 on success, 1 to replace standard code
     */
    public function addHtmlHeader(array $parameters): int
    {
        $this->resprints = '';
        if (strpos($parameters['context'] ?? '', 'tmdlist_saturne') === false) {
            return 0;
        }

        $this->resprints = dolitmd_assets_html();

        return 0;
    }

    /**
     * Cards of the states and quick filters above the list of the TMD requests
     *
     * @param  array        $parameters Hook metadata (context, etc...)
     * @param  CommonObject $object     Current object
     * @return int                      0 < on error, 0 on success, 1 to replace standard code
     */
    public function saturneListTopBanner(array $parameters, $object): int
    {
        global $langs;

        $this->resprints = '';
        if (!is_object($object) || $object->element !== 'tmd') {
            return 0;
        }

        $counts = $object->countByStatus();
        $total  = array_sum($counts);
        $late   = $object->countLate();
        $cards  = [
            ['id' => 'late', 'label' => $langs->transnoentities('TmdLate'), 'value' => (string) $late, 'icon' => 'fas fa-exclamation-triangle', 'color' => $late > 0 ? 'red' : 'grey'],
            ['id' => 'pending', 'label' => $langs->transnoentities('TmdStatusPending'), 'value' => (string) $counts[Tmd::STATUS_PENDING], 'icon' => 'fas fa-hourglass-half', 'color' => 'yellow'],
            ['id' => 'done', 'label' => $langs->transnoentities('TmdStatusDoneToInvoice'), 'value' => (string) $counts[Tmd::STATUS_DONE], 'icon' => 'fas fa-check', 'color' => 'green'],
            ['id' => 'invoiced', 'label' => $langs->transnoentities('TmdStatusInvoiced'), 'value' => (string) $counts[Tmd::STATUS_INVOICED], 'icon' => 'fas fa-file-invoice-dollar', 'color' => 'grey'],
            ['id' => 'total', 'label' => $langs->transnoentities('TmdRequests'), 'value' => (string) $total, 'icon' => 'fas fa-truck-moving', 'color' => 'blue'],
        ];

        // The menu of DoliTMD stays on the left whatever page the user came from
        $listUrl  = dol_buildpath('/saturne/view/saturne_list.php', 1) . '?object_type=tmd&mainmenu=dolitmd&leftmenu=tmd';
        $selected   = GETPOST('search_status', 'array');
        $selected   = is_array($selected) ? array_values(array_map('intval', $selected)) : [];
        $lateActive = GETPOST('search_done', 'alpha') === '0' && GETPOSTISSET('search_date_due_dtendday');
        $presets    = [['label' => $langs->transnoentities('TmdAll'), 'url' => $listUrl . '&button_removefilter=1', 'icon' => 'fas fa-list', 'active' => empty($selected) && !$lateActive]];
        // Late: not done, deadline yesterday at the latest (the filters of the list: follow-up and deadline)
        $yesterday  = dol_time_plus_duree(dol_now(), -1, 'd');
        $presets[]  = [
            'label'  => $langs->transnoentities('TmdLate'),
            'url'    => $listUrl . '&search_done=0&search_date_due_dtendday=' . dol_print_date($yesterday, '%d') . '&search_date_due_dtendmonth=' . dol_print_date($yesterday, '%m') . '&search_date_due_dtendyear=' . dol_print_date($yesterday, '%Y'),
            'icon'   => 'fas fa-exclamation-triangle',
            'active' => $lateActive,
        ];
        foreach ([Tmd::STATUS_PENDING => ['TmdStatusPending', 'fas fa-hourglass-half'], Tmd::STATUS_DONE => ['TmdStatusDoneToInvoice', 'fas fa-check'], Tmd::STATUS_INVOICED => ['TmdStatusInvoiced', 'fas fa-file-invoice-dollar']] as $status => $preset) {
            $presets[] = [
                'label'  => $langs->transnoentities($preset[0]),
                'url'    => $listUrl . '&search_status[]=' . $status,
                'icon'   => $preset[1],
                'active' => $selected === [$status] && !$lateActive,
            ];
        }

        $this->resprints = '<div class="dolitmd-list-banner">' . saturne_render_kpi_cards($cards) . saturne_render_list_presets($presets) . '</div>';

        return 0;
    }

    /**
     * Mass actions of the list of the TMD requests: the states (invoiced first), the deletion and the export. Replaces the
     * standard ones: archiving would write a state the requests do not have
     *
     * @param  array $parameters Hook metadata (context, etc...)
     * @return int               0 < on error, 0 on success, 1 to replace standard code
     */
    public function addMoreMassActions(array $parameters): int
    {
        global $langs, $listXlsxExportAvailable, $permissiontodelete, $user;

        $this->resprints = '';
        if (strpos($parameters['context'] ?? '', 'tmdlist_saturne') === false) {
            return 0;
        }

        $langs->load('dolitmd@dolitmd');
        $actions = ['0' => '-- ' . $langs->trans('SelectAction') . ' --'];
        if ($user->hasRight('dolitmd', 'tmd', 'write') && isModEnabled('invoice') && $user->hasRight('facture', 'creer')) {
            $actions['tmdinvoice'] = '<span class="fas fa-file-invoice paddingrightonly"></span>' . $langs->trans('TmdMassCreateInvoices');
        }
        if ($user->hasRight('dolitmd', 'tmd', 'write')) {
            foreach (dolitmd_mass_states() as $code => $state) {
                $actions[$code] = '<span class="fas ' . $state['icon'] . ' paddingrightonly"></span>' . $langs->trans($state['label']);
            }
        }
        if (!empty($listXlsxExportAvailable)) {
            $actions['export_xlsx'] = '<span class="fas fa-file-excel paddingrightonly"></span>' . $langs->trans('XlsxExportSelection');
        }
        if (!empty($permissiontodelete)) {
            $actions['predelete'] = img_picto('', 'delete', 'class="pictofixedwidth"') . $langs->trans('Delete');
        }
        foreach ($actions as $code => $label) {
            $this->resprints .= '<option value="' . dol_escape_htmltag($code) . '"' . ($code === '0' ? '' : ' data-html="' . dol_escape_htmltag($label) . '"') . '>' . $label . '</option>';
        }

        return 1;
    }

    /**
     * Confirmation of a mass change of state
     *
     * @param  array $parameters Hook metadata (context, etc...)
     * @return int               0 < on error, 0 on success, 1 to replace standard code
     */
    public function doPreMassActions(array $parameters): int
    {
        global $db, $langs, $massaction;

        $this->resprints = '';
        $states          = dolitmd_mass_states();
        // The mass action of the page: emptied by the list once confirmed (the select still posts it)
        $massaction      = (string) $massaction;
        if (strpos($parameters['context'] ?? '', 'tmdlist_saturne') === false || (!isset($states[$massaction]) && $massaction !== 'tmdinvoice')) {
            return 0;
        }

        $langs->load('dolitmd@dolitmd');
        $toselect = (array) ($parameters['toselect'] ?? GETPOST('toselect', 'array'));
        $form     = new Form($db);

        if ($massaction === 'tmdinvoice') {
            $this->resprints = $form->formconfirm($_SERVER['PHP_SELF'], $langs->trans('TmdMassCreateInvoices'), $langs->trans('TmdMassCreateInvoicesQuestion', count($toselect)), 'tmd_create_invoices', [], 'yes', 0, 200, 500, 1);
            return 0;
        }

        $question = [['type' => 'hidden', 'name' => 'tmd_state', 'value' => $states[$massaction]['state']]];

        $this->resprints = $form->formconfirm($_SERVER['PHP_SELF'], $langs->trans($states[$massaction]['label']), $langs->trans('TmdMassStateQuestion', count($toselect), $langs->transnoentities($states[$massaction]['stateLabel'])), 'tmd_set_state', $question, 'yes', 0, 200, 500, 1);

        return 0;
    }

    /**
     * Mass change of state, once confirmed: each request checked takes the state (its ticket follows)
     *
     * @param  array        $parameters Hook metadata (context, etc...)
     * @param  CommonObject $object     Current object
     * @param  string       $action     Current action
     * @return int                      0 < on error, 0 on success, 1 to replace standard code
     */
    public function doActions(array $parameters, $object, $action): int
    {
        global $langs, $user;

        if (!is_object($object) || $object->element !== 'tmd' || !in_array($action, ['tmd_set_state', 'tmd_create_invoices'], true) || GETPOST('confirm', 'alpha') !== 'yes') {
            return 0;
        }
        if (!$user->hasRight('dolitmd', 'tmd', 'write')) {
            setEventMessages($langs->trans('NotEnoughPermissions'), [], 'errors');
            return 0;
        }

        $langs->load('dolitmd@dolitmd');

        if ($action === 'tmd_create_invoices') {
            return $this->createInvoices();
        }
        $state   = GETPOSTINT('tmd_state');
        $changed = 0;
        $errors  = [];
        // The closing of the tickets runs their triggers: a warning they print would land above the page
        ob_start();
        foreach (array_map('intval', (array) GETPOST('toselect', 'array')) as $id) {
            $tmd = new Tmd($this->db);
            if ($id <= 0 || $tmd->fetch($id) <= 0 || (int) $tmd->status === Tmd::STATUS_DELETED) {
                continue;
            }
            // Already in this state, ticks included
            if ((int) $tmd->status === $state && (int) $tmd->done === ($state >= Tmd::STATUS_DONE ? 1 : 0) && (int) $tmd->invoiced === ($state == Tmd::STATUS_INVOICED ? 1 : 0)) {
                continue;
            }
            if ($tmd->setState($user, $state) > 0) {
                $changed++;
            } else {
                $errors[] = $tmd->ref . ' : ' . $tmd->error;
            }
        }
        $printed = ob_get_clean();
        if (trim((string) $printed) !== '') {
            dol_syslog(__METHOD__ . ' output dropped: ' . dol_trunc(strip_tags((string) $printed), 500), LOG_WARNING);
        }

        if ($changed > 0) {
            setEventMessages($langs->trans('TmdMassStateDone', $changed, $langs->transnoentities((string) (Tmd::STATE_LABELS[$state] ?? ''))), []);
        }
        if ($errors) {
            setEventMessages($langs->trans('TmdFlagError'), $errors, 'errors');
        }

        return 0;
    }

    /**
     * Invoices of the requests checked, once confirmed: one draft invoice per thirdparty
     *
     * @return int 0
     */
    protected function createInvoices(): int
    {
        global $langs, $user;

        require_once __DIR__ . '/tmdinvoicer.class.php';

        $invoicer = new TmdInvoicer($this->db);
        // The closing of the tickets runs their triggers: a warning they print would land above the page
        ob_start();
        $made    = $invoicer->createInvoices($user, array_map('intval', (array) GETPOST('toselect', 'array')));
        $printed = ob_get_clean();
        if (trim((string) $printed) !== '') {
            dol_syslog(__METHOD__ . ' output dropped: ' . dol_trunc(strip_tags((string) $printed), 500), LOG_WARNING);
        }

        if ($made > 0) {
            setEventMessages($langs->trans('TmdMassInvoicesDone', $made), $invoicer->invoices);
        }
        if ($invoicer->skipped) {
            setEventMessages($langs->trans('TmdMassInvoicesSkipped'), $invoicer->skipped, 'warnings');
        }
        if ($invoicer->errors) {
            setEventMessages($langs->trans('TmdMassInvoicesError'), $invoicer->errors, 'errors');
        }

        return 0;
    }

    /**
     * Type of the templates of the automatic mails in Setup > Emails > Templates
     *
     * @param  array $parameters Hook metadata (context, etc...)
     * @return int               0 < on error, 0 on success, 1 to replace standard code
     */
    public function emailElementlist(array $parameters): int
    {
        global $langs;

        $this->results = [];
        if (strpos($parameters['context'] ?? '', 'emailtemplates') !== false && isModEnabled('dolitmd')) {
            $langs->load('dolitmd@dolitmd');
            $this->results = ['dolitmd_tmd' => img_picto('', 'fontawesome_fa-truck-moving_fas_#E67E22', 'class="pictofixedwidth"') . dol_escape_htmltag($langs->trans('TmdRequests'))];
        }

        return 0;
    }

    /**
     * The ticks done and invoiced of the list, saved at once (js/modules/tmd.js)
     *
     * @param  array        $parameters Hook metadata (context, etc...)
     * @param  CommonObject $object     Current object
     * @return int                      0 < on error, 0 on success, 1 to replace standard code
     */
    public function saturnePrintFieldListLoopObject(array $parameters, $object): int
    {
        global $user;

        global $langs;

        $this->results = [];
        if (!is_object($object) || $object->element !== 'tmd') {
            return 0;
        }

        $key = $parameters['key'] ?? '';
        if ($key === 'done') {
            // Column « Follow-up »: done and invoiced side by side
            $this->results[$key] = dolitmd_flags_html($object, $user->hasRight('dolitmd', 'tmd', 'write'));
        } elseif ($key === 'status') {
            $this->results[$key] = dolitmd_state_html($object, $user->hasRight('dolitmd', 'tmd', 'write'));
        } elseif ($key === 'date_due') {
            $this->results[$key] = dolitmd_due_html($object);
        } elseif ($key === 'label' && (int) $object->nb_replies > 0) {
            // Answers of the sender added to the ticket: a badge before the subject (cut when long)
            $title = $langs->transnoentities('TmdRepliesCount', (int) $object->nb_replies);
            if (!empty($object->date_last_reply)) {
                $title .= ' — ' . $langs->transnoentities('TmdDateLastReply') . ' : ' . dol_print_date($object->date_last_reply, 'dayhour', 'tzuserrel');
            }
            $this->results[$key] = '<span class="dolitmd-replies classfortooltip" title="' . dol_escape_htmltag($title) . '"><i class="fas fa-comment-dots"></i> ' . ((int) $object->nb_replies) . '</span> '
                . dol_escape_htmltag((string) $object->label);
        } elseif ($key === 'fk_soc' && empty($object->fk_soc) && !empty($object->email_from)) {
            // No thirdparty nor contact with the address of the sender: the address itself, so that the request is known
            $this->results[$key] = '<span class="opacitymedium classfortooltip" title="' . dol_escape_htmltag($langs->transnoentities('TmdUnknownSender')) . '">'
                . img_picto('', 'email', 'class="pictofixedwidth"') . dol_escape_htmltag($object->email_from) . '</span>';
        }

        return 0;
    }
}
