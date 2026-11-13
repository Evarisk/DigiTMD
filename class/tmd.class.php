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
 * \file    class/tmd.class.php
 * \ingroup dolitmd
 * \brief   TMD request: a mail received on the TMD mailbox (or typed by hand) and its ticket
 */

// Load Dolibarr libraries
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';

// Load Saturne libraries
dol_include_once('/saturne/class/saturneobject.class.php');

/**
 * Class for TMD request
 */
class Tmd extends SaturneObject
{
    /**
     * @var string Module name
     */
    public $module = 'dolitmd';

    /**
     * @var string Element type of object
     */
    public $element = 'tmd';

    /**
     * @var string Name of table without prefix where object is stored
     */
    public $table_element = 'dolitmd_tmd';

    /**
     * @var int Does this object support multicompany module ?
     */
    public $ismultientitymanaged = 1;

    /**
     * @var int Does object support extrafields ? 0 = No, 1 = Yes
     */
    public $isextrafieldmanaged = 0;

    /**
     * @var int<0,1> Does object support category module ? 0 = No, 1 = Yes
     *               No: the module declares no tag type, there is no table categorie_tmd (Saturne defaults to 1)
     */
    public int $isCategoryManaged = 0;

    /**
     * @var string Field linking a line to its request: read by the list of the files of the tab Documents
     *             (core/tpl/ajaxrow.tpl.php warns when it is empty), a request has no line
     */
    public $fk_element = 'fk_tmd';

    /**
     * @var string Name of icon for tmd
     */
    public string $picto = 'fontawesome_fa-truck-moving_fas_#E67E22';

    /**
     * Characters of the tracking numbers (Crockford's base 32: no I, L, O nor U, read back from a paper or a phone)
     */
    public const TRACK_ID_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * The state is given by the two ticks: invoiced wins over done
     */
    public const STATUS_DELETED  = -1;
    public const STATUS_PENDING  = 0;
    public const STATUS_DONE     = 1;
    public const STATUS_INVOICED = 2;

    /**
     * Translation keys of the states
     */
    public const STATE_LABELS = [self::STATUS_PENDING => 'TmdStatusPending', self::STATUS_DONE => 'TmdStatusDone', self::STATUS_INVOICED => 'TmdStatusInvoiced'];

    /**
     * @var array Array with all fields and their property. Do not use it as a static var. It may be modified by constructor
     */
    public $fields = [
        'rowid'            => ['type' => 'integer',      'label' => 'TechnicalID',      'enabled' => 1, 'position' => 1,   'notnull' => 1, 'visible' => 0, 'noteditable' => 1, 'index' => 1, 'comment' => 'Id'],
        'ref'              => ['type' => 'varchar(128)', 'label' => 'Ref',              'enabled' => 1, 'position' => 10,  'notnull' => 1, 'visible' => 4, 'noteditable' => 1, 'default' => '(PROV)', 'index' => 1, 'searchall' => 1, 'showoncombobox' => 1, 'validate' => 1],
        'ref_ext'          => ['type' => 'varchar(128)', 'label' => 'RefExt',           'enabled' => 1, 'position' => 20,  'notnull' => 0, 'visible' => 0],
        'entity'           => ['type' => 'integer',      'label' => 'Entity',           'enabled' => 1, 'position' => 30,  'notnull' => 1, 'visible' => 0, 'index' => 1],
        // In the list: the request, its thirdparty, its state and ticks, the nomeref ID, then the mail
        'date_mail'        => ['type' => 'datetime',     'label' => 'TmdDateMail',      'enabled' => 1, 'position' => 40,  'notnull' => 0, 'visible' => 1, 'noedit' => 1, 'csslist' => 'nowraponall'],
        'date_due'         => ['type' => 'date',         'label' => 'TmdDateDue',       'enabled' => 1, 'position' => 45,  'notnull' => 0, 'visible' => 1, 'index' => 1, 'csslist' => 'nowraponall', 'help' => 'TmdDateDueHelp'],
        'fk_soc'           => ['type' => 'integer:Societe:societe/class/societe.class.php:1', 'label' => 'ThirdParty', 'picto' => 'company', 'enabled' => 'isModEnabled("societe")', 'position' => 50, 'notnull' => 0, 'visible' => 1, 'index' => 1, 'css' => 'maxwidth500 widthcentpercentminusxx', 'csslist' => 'tdoverflowmax150'],
        'fk_socpeople'     => ['type' => 'integer:Contact:contact/class/contact.class.php:1', 'label' => 'Contact', 'picto' => 'contact', 'enabled' => 'isModEnabled("societe")', 'position' => 55, 'notnull' => 0, 'visible' => -1, 'css' => 'maxwidth500 widthcentpercentminusxx', 'csslist' => 'tdoverflowmax150'],
        'status'           => ['type' => 'smallint',     'label' => 'TmdState',         'enabled' => 1, 'position' => 60,  'notnull' => 1, 'visible' => 5, 'default' => 0, 'index' => 1, 'arrayofkeyval' => [0 => 'TmdStatusPending', 1 => 'TmdStatusDone', 2 => 'TmdStatusInvoiced'], 'searchmulti' => 1, 'csslist' => 'center nowraponall', 'help' => 'TmdStateHelp'],
        // Done and invoiced: one column « Follow-up » in the list (two pictos, the column of done), each one on the card
        'done'             => ['type' => 'smallint',     'label' => 'TmdDone',          'enabled' => 1, 'position' => 65,  'notnull' => 1, 'visible' => 1, 'default' => 0, 'arrayofkeyval' => [0 => 'No', 1 => 'Yes'], 'csslist' => 'center nowraponall'],
        'invoiced'         => ['type' => 'smallint',     'label' => 'TmdInvoiced',      'enabled' => 1, 'position' => 70,  'notnull' => 1, 'visible' => 3, 'default' => 0, 'arrayofkeyval' => [0 => 'No', 1 => 'Yes'], 'help' => 'TmdInvoicedHelp'],
        'software_ref'     => ['type' => 'varchar(128)', 'label' => 'TmdSoftwareRef',   'enabled' => 1, 'position' => 75,  'notnull' => 0, 'visible' => 1, 'searchall' => 1, 'css' => 'minwidth200', 'csslist' => 'minwidth100', 'help' => 'TmdSoftwareRefHelp'],
        'fk_ticket'        => ['type' => 'integer:Ticket:ticket/class/ticket.class.php', 'label' => 'Ticket', 'picto' => 'ticket', 'enabled' => 'isModEnabled("ticket")', 'position' => 80, 'notnull' => 0, 'visible' => 1, 'noteditable' => 1, 'index' => 1, 'csslist' => 'nowraponall'],
        'label'            => ['type' => 'varchar(255)', 'label' => 'TmdSubject',       'enabled' => 1, 'position' => 85,  'notnull' => 0, 'visible' => 1, 'noedit' => 1, 'searchall' => 1, 'css' => 'minwidth300 widthcentpercentminusxx', 'csslist' => 'tdoverflowmax150', 'showoncombobox' => 2],
        'nb_replies'       => ['type' => 'integer',      'label' => 'TmdReplies',       'enabled' => 1, 'position' => 87,  'notnull' => 1, 'visible' => -5, 'default' => 0, 'noteditable' => 1, 'noedit' => 1, 'csslist' => 'center'],
        'date_last_reply'  => ['type' => 'datetime',     'label' => 'TmdDateLastReply', 'enabled' => 1, 'position' => 88,  'notnull' => 0, 'visible' => -5, 'noteditable' => 1, 'noedit' => 1, 'csslist' => 'nowraponall'],
        'fk_facture'       => ['type' => 'integer:Facture:compta/facture/class/facture.class.php', 'label' => 'TmdInvoice', 'picto' => 'bill', 'enabled' => 'isModEnabled("invoice")', 'position' => 89, 'notnull' => 0, 'visible' => -5, 'noteditable' => 1, 'index' => 1, 'csslist' => 'nowraponall'],
        'email_from'       => ['type' => 'email',        'label' => 'TmdEmailFrom',     'enabled' => 1, 'position' => 90,  'notnull' => 0, 'visible' => -1, 'noedit' => 1, 'searchall' => 1, 'css' => 'minwidth300', 'csslist' => 'tdoverflowmax150'],
        'date_done'        => ['type' => 'datetime',     'label' => 'TmdDateDone',      'enabled' => 1, 'position' => 130, 'notnull' => 0, 'visible' => -5, 'noteditable' => 1, 'noedit' => 1],
        'fk_user_done'     => ['type' => 'integer:User:user/class/user.class.php', 'label' => 'TmdUserDone', 'picto' => 'user', 'enabled' => 1, 'position' => 135, 'notnull' => 0, 'visible' => -5, 'noteditable' => 1, 'foreignkey' => 'user.rowid'],
        'date_invoiced'    => ['type' => 'datetime',     'label' => 'TmdDateInvoiced',  'enabled' => 1, 'position' => 140, 'notnull' => 0, 'visible' => -5, 'noteditable' => 1, 'noedit' => 1],
        'fk_user_invoiced' => ['type' => 'integer:User:user/class/user.class.php', 'label' => 'TmdUserInvoiced', 'picto' => 'user', 'enabled' => 1, 'position' => 145, 'notnull' => 0, 'visible' => -5, 'noteditable' => 1, 'foreignkey' => 'user.rowid'],
        'message_id'       => ['type' => 'varchar(255)', 'label' => 'TmdMessageId',     'enabled' => 1, 'position' => 150, 'notnull' => 0, 'visible' => 0, 'noteditable' => 1],
        'track_id'         => ['type' => 'varchar(32)',  'label' => 'TmdTrackId',       'enabled' => 1, 'position' => 155, 'notnull' => 0, 'visible' => 0, 'noteditable' => 1],
        'date_creation'    => ['type' => 'datetime',     'label' => 'DateCreation',     'enabled' => 1, 'position' => 160, 'notnull' => 1, 'visible' => -2],
        'tms'              => ['type' => 'timestamp',    'label' => 'DateModification', 'enabled' => 1, 'position' => 170, 'notnull' => 0, 'visible' => 0],
        'import_key'       => ['type' => 'varchar(14)',  'label' => 'ImportId',         'enabled' => 1, 'position' => 180, 'notnull' => 0, 'visible' => 0],
        'fk_user_creat'    => ['type' => 'integer:User:user/class/user.class.php', 'label' => 'UserAuthor', 'picto' => 'user', 'enabled' => 1, 'position' => 190, 'notnull' => 1, 'visible' => 0, 'foreignkey' => 'user.rowid'],
        'fk_user_modif'    => ['type' => 'integer:User:user/class/user.class.php', 'label' => 'UserModif',  'picto' => 'user', 'enabled' => 1, 'position' => 200, 'notnull' => 0, 'visible' => 0, 'foreignkey' => 'user.rowid'],
    ];

    /**
     * @var int ID
     */
    public int $rowid;

    /**
     * @var string Ref
     */
    public $ref;

    /**
     * @var string Ref ext
     */
    public $ref_ext;

    /**
     * @var int Entity
     */
    public $entity;

    /**
     * @var int|string Creation date
     */
    public $date_creation;

    /**
     * @var int|string Timestamp
     */
    public $tms;

    /**
     * @var string Import key
     */
    public $import_key;

    /**
     * @var int Status
     */
    public $status = self::STATUS_PENDING;

    /**
     * @var string|null Subject of the mail
     */
    public $label;

    /**
     * @var string|null Identifier of the request in nomeref (ID nomeref)
     */
    public $software_ref;

    /**
     * @var int|null Thirdparty
     */
    public $fk_soc;

    /**
     * @var int|null Contact
     */
    public $fk_socpeople;

    /**
     * @var int|null Ticket
     */
    public $fk_ticket;

    /**
     * @var string|null Sender of the mail
     */
    public $email_from;

    /**
     * @var string|null Message-ID of the mail (one request per mail)
     */
    public $message_id;

    /**
     * @var string|null Tracking number of the public page of the request (XXXX-XXXX-XXXX-XXXX)
     */
    public $track_id;

    /**
     * @var int|string|null Date of the mail
     */
    public $date_mail;

    /**
     * @var int|string|null Deadline of the request (date of the mail + the delay of the setup)
     */
    public $date_due;

    /**
     * @var int Answers of the sender added to the ticket
     */
    public $nb_replies = 0;

    /**
     * @var int|string|null Date of the last answer
     */
    public $date_last_reply;

    /**
     * @var int|null Invoice of the request
     */
    public $fk_facture;

    /**
     * @var int Done
     */
    public $done = 0;

    /**
     * @var int|string|null Date done
     */
    public $date_done;

    /**
     * @var int|null User who ticked done
     */
    public $fk_user_done;

    /**
     * @var int Invoiced
     */
    public $invoiced = 0;

    /**
     * @var int|string|null Date invoiced
     */
    public $date_invoiced;

    /**
     * @var int|null User who ticked invoiced
     */
    public $fk_user_invoiced;

    /**
     * @var int User ID
     */
    public $fk_user_creat;

    /**
     * @var int|null User ID
     */
    public $fk_user_modif;

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct(DoliDB $db)
    {
        // The column of done shows both pictos in the list: titled for both there
        if (strpos($_SERVER['PHP_SELF'] ?? '', 'saturne_list.php') !== false) {
            $this->fields['done']['label'] = 'TmdFollowUp';
            $this->fields['done']['help']  = 'TmdFollowUpHelp';
        }

        parent::__construct($db, $this->module, $this->element);
    }

    /**
     * Create object into database: the reference is taken from the numbering module first, the state follows the ticks
     *
     * @param  User     $user      User that creates
     * @param  int<0,1> $noTrigger 0 = launch triggers after, 1 = disable triggers
     * @return int                 Return integer < 0 if KO, ID of created object if OK
     */
    public function create(User $user, int $noTrigger = 0): int
    {
        if (empty($this->ref) || $this->ref == '(PROV)') {
            $this->ref = $this->getNextNumRef();
            if ($this->ref === '') {
                return -1;
            }
        }
        $this->stampFlags($user, null);
        if (empty($this->track_id)) {
            $this->track_id = self::generateTrackId();
        }

        // Deadline: the date of the mail (or today) plus the delay of the setup, 0 = no deadline
        $delay = getDolGlobalInt('DOLITMD_DELAY_DAYS', 15);
        if (empty($this->date_due) && $delay > 0) {
            $base           = !empty($this->date_mail) ? (is_numeric($this->date_mail) ? (int) $this->date_mail : (int) $this->db->jdate($this->date_mail)) : dol_now();
            $this->date_due = dol_time_plus_duree($base, $delay, 'd');
        }

        return parent::create($user, $noTrigger);
    }

    /**
     * The request is late: not done, its deadline before today
     *
     * @return bool
     */
    public function isLate(): bool
    {
        if (!empty($this->done) || empty($this->date_due) || (int) $this->status === self::STATUS_DELETED) {
            return false;
        }
        $due = is_numeric($this->date_due) ? (int) $this->date_due : (int) $this->db->jdate($this->date_due);

        return $due < dol_get_first_hour(dol_now());
    }

    /**
     * Number of late requests (not done, deadline before today)
     *
     * @return int
     */
    public function countLate(): int
    {
        $obj = $this->db->getRow('SELECT COUNT(rowid) AS nb FROM ' . $this->db->prefix() . $this->table_element
            . ' WHERE entity IN (' . getEntity($this->element) . ') AND status >= 0 AND done = 0 AND date_due IS NOT NULL'
            . " AND date_due < '" . $this->db->idate(dol_get_first_hour(dol_now())) . "'");

        return is_object($obj) ? (int) $obj->nb : 0;
    }

    /**
     * New tracking number: 16 random characters (80 bits), by groups of 4
     *
     * @return string XXXX-XXXX-XXXX-XXXX
     * @throws Exception
     */
    public static function generateTrackId(): string
    {
        $chars = '';
        for ($i = 0; $i < 16; $i++) {
            $chars .= self::TRACK_ID_ALPHABET[random_int(0, 31)];
        }

        return implode('-', str_split($chars, 4));
    }

    /**
     * Tracking number as typed (lower case, spaces, no dashes, O for 0, I or L for 1) in its saved form
     *
     * @param  string $value Number typed
     * @return string        XXXX-XXXX-XXXX-XXXX, empty when it cannot be one
     */
    public static function normalizeTrackId(string $value): string
    {
        $clean = strtr(preg_replace('/[^A-Z0-9]/', '', strtoupper($value)), ['O' => '0', 'I' => '1', 'L' => '1']);
        if (strlen($clean) !== 16 || strspn($clean, self::TRACK_ID_ALPHABET) !== 16) {
            return '';
        }

        return implode('-', str_split($clean, 4));
    }

    /**
     * Tracking number of the request, given now to a request made before the tracking numbers
     *
     * @return string XXXX-XXXX-XXXX-XXXX, empty when it could not be saved
     */
    public function ensureTrackId(): string
    {
        if (!empty($this->track_id) || $this->id <= 0) {
            return (string) $this->track_id;
        }
        for ($try = 0; $try < 3; $try++) {
            $trackId = self::generateTrackId();
            $sql     = 'UPDATE ' . $this->db->prefix() . $this->table_element . " SET track_id = '" . $this->db->escape($trackId) . "'";
            $sql    .= ' WHERE rowid = ' . ((int) $this->id) . ' AND track_id IS NULL';
            if ($this->db->query($sql)) {
                $saved = $this->db->getRow('SELECT track_id FROM ' . $this->db->prefix() . $this->table_element . ' WHERE rowid = ' . ((int) $this->id));
                $this->track_id = is_object($saved) ? (string) $saved->track_id : '';
                return $this->track_id;
            }
        }
        dol_syslog(__METHOD__ . ' no tracking number for ' . $this->ref . ': ' . $this->db->lasterror(), LOG_ERR);

        return '';
    }

    /**
     * Load the request of a tracking number, in the entity of the page; never a deleted one
     *
     * @param  string $trackId Tracking number, as typed
     * @return int             > 0 if found, 0 if not
     */
    public function fetchByTrackId(string $trackId): int
    {
        global $conf;

        $trackId = self::normalizeTrackId($trackId);
        if ($trackId === '') {
            return 0;
        }
        $obj = $this->db->getRow('SELECT rowid FROM ' . $this->db->prefix() . $this->table_element . " WHERE track_id = '" . $this->db->escape($trackId) . "'"
            . ' AND entity = ' . ((int) $conf->entity) . ' AND status >= 0');
        if (!is_object($obj) || $this->fetch((int) $obj->rowid) <= 0) {
            return 0;
        }

        return 1;
    }

    /**
     * Address of the public page of the request (sent in the mails to the sender)
     *
     * @return string Empty when the request has no tracking number
     */
    public function getPublicUrl(): string
    {
        $trackId = $this->ensureTrackId();
        if ($trackId === '') {
            return '';
        }

        return dol_buildpath('/dolitmd/public/tmd/tracking.php', 2) . '?track_id=' . urlencode($trackId) . '&entity=' . ((int) ($this->entity ?: 1));
    }

    /**
     * Directory of the documents of the request (tab Documents)
     *
     * @return string
     */
    public function getUploadDir(): string
    {
        global $conf;

        $entity = (int) ($this->entity ?: $conf->entity);
        $root   = $conf->dolitmd->multidir_output[$entity] ?? ($conf->dolitmd->dir_output ?? (DOL_DATA_ROOT . ($entity > 1 ? '/' . $entity : '') . '/dolitmd'));

        return $root . '/' . $this->element . '/' . dol_sanitizeFileName($this->ref);
    }

    /**
     * Documents shown on the public page: all the files of the tab Documents, the newest first (never the hidden
     * files, the thumbnails, nor the files renamed .noexe by the upload)
     *
     * @return array<int,array{name:string,size:int,date:int,fullname:string}>
     */
    public function getPublicFiles(): array
    {
        require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

        $out = [];
        if (empty($this->ref)) {
            return $out;
        }
        foreach (dol_dir_list($this->getUploadDir(), 'files', 0, '', '(\.meta|_preview.*\.png|\.noexe)$', 'date', SORT_DESC, 1) as $file) {
            if ($file['name'][0] === '.') {
                continue;
            }
            $out[] = ['name' => (string) $file['name'], 'size' => (int) $file['size'], 'date' => (int) $file['date'], 'fullname' => (string) $file['fullname']];
        }

        return $out;
    }

    /**
     * Under the banner of the tab Documents (saturne_document.php): its files are seen by the customer
     *
     * @param  int    $id Id of the request
     * @return string
     */
    public function getMoreHtmlRef(int $id): string
    {
        global $langs;

        if (strpos($_SERVER['PHP_SELF'] ?? '', 'saturne_document.php') === false || $this->id <= 0) {
            return '';
        }
        $url = $this->getPublicUrl();
        if ($url === '') {
            return '';
        }
        $langs->load('dolitmd@dolitmd');

        return '<div class="dolitmd-documents-notice"><i class="fas fa-globe pictofixedwidth"></i>' . $langs->trans('TmdPublicDocumentsNotice')
            . ' <a href="' . dol_escape_htmltag($url) . '" target="_blank" rel="noopener">' . $langs->trans('TmdPublicOpen') . '</a></div>';
    }

    /**
     * Update object into database: the state follows the ticks
     *
     * @param  User     $user      User that modifies
     * @param  int<0,1> $noTrigger 0 = launch triggers after, 1 = disable triggers
     * @return int                 Return integer < 0 if KO, > 0 if OK
     */
    public function update(User $user, int $noTrigger = 0): int
    {
        $before = null;
        if ($this->id > 0) {
            $before = $this->db->getRow('SELECT done, invoiced FROM ' . $this->db->prefix() . $this->table_element . ' WHERE rowid = ' . ((int) $this->id));
        }
        $this->stampFlags($user, is_object($before) ? $before : null);

        $result = parent::update($user, $noTrigger);
        // Done now, or pending again: the ticket follows (closed as solved, opened again)
        if ($result > 0 && is_object($before) && (int) $before->done !== (int) $this->done) {
            $this->syncTicket($user);
            // Done now: the sender is told, when the setup asks for it
            if ($this->done) {
                require_once __DIR__ . '/tmdmailer.class.php';
                (new TmdMailer($this->db))->sendDone($this, $user);
            }
        }

        return $result;
    }

    /**
     * The ticket of the request follows it: closed as solved when the request is done, opened again when it is pending
     * again (as the button « Reopen » of the ticket: assigned when it has someone, else not read)
     *
     * @param  User $user User changing the request
     * @return void
     */
    protected function syncTicket(User $user): void
    {
        if (empty($this->fk_ticket) || !isModEnabled('ticket')) {
            return;
        }
        require_once DOL_DOCUMENT_ROOT . '/ticket/class/ticket.class.php';

        $ticket = new Ticket($this->db);
        if ($ticket->fetch((int) $this->fk_ticket) <= 0) {
            return;
        }
        if ($this->done) {
            $result = $ticket->close($user, 0);
        } elseif (in_array((int) $ticket->status, [Ticket::STATUS_CLOSED, Ticket::STATUS_CANCELED], true)) {
            $result = $ticket->setStatut($ticket->fk_user_assign > 0 ? Ticket::STATUS_ASSIGNED : Ticket::STATUS_NOT_READ, null, '', 'TICKET_MODIFY');
        } else {
            return;
        }
        if ($result < 0) {
            dol_syslog(__METHOD__ . ' ticket ' . $ticket->id . ' of request ' . $this->ref . ' not changed: ' . $ticket->error, LOG_WARNING);
        }
    }

    /**
     * Tick or untick done or invoiced, then save. Invoiced means done: ticking it ticks done too
     *
     * @param  User   $user  User ticking
     * @param  string $field done or invoiced
     * @param  int    $value 1 ticked, 0 not ticked
     * @return int           Return integer < 0 if KO, > 0 if OK
     */
    public function setFlag(User $user, string $field, int $value): int
    {
        if (!in_array($field, ['done', 'invoiced'], true)) {
            $this->error = 'BadField';
            return -1;
        }
        // Invoiced means done: ticking invoiced ticks done, unticking done unticks invoiced
        $this->$field = $value ? 1 : 0;
        if ($field == 'invoiced' && $this->invoiced) {
            $this->done = 1;
        }
        if ($field == 'done' && !$this->done) {
            $this->invoiced = 0;
        }

        $result = $this->update($user);
        if ($result > 0) {
            $this->call_trigger(strtoupper($this->element) . '_' . strtoupper($field) . ($value ? '' : '_CANCEL'), $user);
        }

        return $result;
    }

    /**
     * Choose the state, then save: the ticks follow (pending = none, done = done, invoiced = done and invoiced)
     *
     * @param  User $user  User choosing
     * @param  int  $state STATUS_PENDING, STATUS_DONE or STATUS_INVOICED
     * @return int         Return integer < 0 if KO, > 0 if OK
     */
    public function setState(User $user, int $state): int
    {
        if (!in_array($state, [self::STATUS_PENDING, self::STATUS_DONE, self::STATUS_INVOICED], true)) {
            $this->error = 'BadState';
            return -1;
        }
        $this->done     = $state >= self::STATUS_DONE ? 1 : 0;
        $this->invoiced = $state == self::STATUS_INVOICED ? 1 : 0;

        return $this->update($user);
    }

    /**
     * State from the ticks, and who ticked them and when (kept while ticked, cleared when unticked)
     *
     * @param  User        $user   User saving
     * @param  object|null $before Ticks saved in the database (null on creation)
     * @return void
     */
    protected function stampFlags(User $user, ?object $before): void
    {
        $this->done     = empty($this->done) ? 0 : 1;
        $this->invoiced = empty($this->invoiced) ? 0 : 1;
        foreach (['done', 'invoiced'] as $field) {
            $dateField = 'date_' . $field;
            $userField = 'fk_user_' . $field;
            $wasTicked = $before !== null && !empty($before->$field);
            // Ticked now: stamped, unless a date is given on creation (request typed by hand afterwards)
            if ($this->$field && !$wasTicked && ($before !== null || empty($this->$dateField))) {
                $this->$dateField = dol_now();
                $this->$userField = $user->id > 0 ? $user->id : null;
            } elseif (!$this->$field) {
                $this->$dateField = null;
                $this->$userField = null;
            }
        }
        if ((int) $this->status !== self::STATUS_DELETED) {
            $this->status = $this->invoiced ? self::STATUS_INVOICED : ($this->done ? self::STATUS_DONE : self::STATUS_PENDING);
        }
    }

    /**
     * The ticks done and invoiced are shown as ticks, saved at once (js/modules/tmd.js), on the card as in the list
     *
     * @param  array        $val       Array of properties for field to show
     * @param  string       $key       Key of attribute
     * @param  mixed        $value     Preselected value to show (for date type it must be in timestamp format, for amount or price it must be a php numeric value)
     * @param  string       $moreparam To add more parameters on html input tag
     * @param  string       $keysuffix Prefix string to add into name and id of field (can be used to avoid duplicate names)
     * @param  string       $keyprefix Suffix string to add into name and id of field (can be used to avoid duplicate names)
     * @param  mixed        $morecss   Value for css to define size. May also be a numeric.
     * @return string
     */
    public function showOutputField($val, $key, $value, $moreparam = '', $keysuffix = '', $keyprefix = '', $morecss = '')
    {
        global $user;

        if (in_array($key, ['done', 'invoiced'], true) && $this->id > 0) {
            require_once __DIR__ . '/../lib/dolitmd.lib.php';
            return dolitmd_flag_html($this, $key, $user->hasRight('dolitmd', 'tmd', 'write'));
        }
        // Deadline: late or near, as in the list
        if ($key === 'date_due' && $this->id > 0) {
            require_once __DIR__ . '/../lib/dolitmd.lib.php';
            return dolitmd_due_html($this);
        }

        return parent::showOutputField($val, $key, $value, $moreparam, $keysuffix, $keyprefix, $morecss);
    }

    /**
     * A TMD request stays editable whatever its state (the ticks may be changed back), deleted ones excepted
     *
     * @return bool
     */
    public function isModifiable(): bool
    {
        return (int) $this->status >= self::STATUS_PENDING;
    }

    /**
     * A TMD request is never locked
     *
     * @return bool
     */
    public function isLockable(): bool
    {
        return false;
    }

    /**
     * Return the label of a status
     *
     * @param  int    $status Id status
     * @param  int    $mode   0 = long label, 1 = short label, 2 = Picto + short label, 3 = Picto, 4 = Picto + long label, 5 = Short label + Picto, 6 = Long label + Picto
     * @return string         Label of status
     */
    public function LibStatut(int $status, int $mode = 0): string
    {
        global $langs;

        $labels = [
            self::STATUS_DELETED  => ['Deleted', 'status9'],
            // Amber to do, green done (to invoice), grey closed: the colours of the cards above the list
            self::STATUS_PENDING  => ['TmdStatusPending', 'status1'],
            self::STATUS_DONE     => ['TmdStatusDone', 'status4'],
            self::STATUS_INVOICED => ['TmdStatusInvoiced', 'status6'],
        ];
        $info  = $labels[$status] ?? $labels[self::STATUS_PENDING];
        $label = $langs->transnoentitiesnoconv($info[0]);

        return dolGetStatus($label, $label, '', $info[1], $mode);
    }

    /**
     * Count the requests per state, for the cards above the list
     *
     * @return array<int,int> State => number of requests
     */
    public function countByStatus(): array
    {
        $out = [self::STATUS_PENDING => 0, self::STATUS_DONE => 0, self::STATUS_INVOICED => 0];
        $sql = 'SELECT status, COUNT(rowid) AS nb FROM ' . $this->db->prefix() . $this->table_element;
        $sql .= ' WHERE entity IN (' . getEntity($this->element) . ') AND status >= 0 GROUP BY status';
        $resql = $this->db->query($sql);
        while ($resql && ($obj = $this->db->fetch_object($resql))) {
            $out[(int) $obj->status] = (int) $obj->nb;
        }

        return $out;
    }

    /**
     * Request already made for a mail
     *
     * @param  string $messageId Message-ID of the mail
     * @return int               Id of the request, 0 when none
     */
    public function fetchIdByMessageId(string $messageId): int
    {
        if ($messageId === '') {
            return 0;
        }
        $obj = $this->db->getRow('SELECT rowid FROM ' . $this->db->prefix() . $this->table_element . " WHERE message_id = '" . $this->db->escape($messageId) . "' AND entity IN (" . getEntity($this->element) . ')');

        return is_object($obj) ? (int) $obj->rowid : 0;
    }

    /**
     * Return description of the request for the event of the agenda
     *
     * @return string
     */
    public function getTriggerDescription(): string
    {
        global $langs;

        $ret = parent::getTriggerDescription();
        $ret .= (dol_strlen((string) $this->email_from) > 0 ? $langs->transnoentities('TmdEmailFrom') . ' : ' . $this->email_from . '<br>' : '');
        $ret .= (dol_strlen((string) $this->software_ref) > 0 ? $langs->transnoentities('TmdSoftwareRef') . ' : ' . $this->software_ref . '<br>' : '');

        return $ret;
    }
}
