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
     * The state is given by the two ticks: invoiced wins over done. Neither ticked: pending, or subcontracted when chosen
     * so (the request is handled by a subcontractor: greyed in the list, never late)
     */
    public const STATUS_DELETED       = -1;
    public const STATUS_PENDING       = 0;
    public const STATUS_DONE          = 1;
    public const STATUS_INVOICED      = 2;
    public const STATUS_SUBCONTRACTED = 3;

    /**
     * Translation keys of the states, in the order of the choices
     */
    public const STATE_LABELS = [self::STATUS_PENDING => 'TmdStatusPending', self::STATUS_SUBCONTRACTED => 'TmdStatusSubcontracted', self::STATUS_DONE => 'TmdStatusDone', self::STATUS_INVOICED => 'TmdStatusInvoiced'];

    /**
     * Categories of a request: the regulation of the transport, road, air or sea
     */
    public const CATEGORIES = ['ADR', 'IATA', 'IMDG'];

    /**
     * Words of a subject naming each category
     */
    public const CATEGORY_PATTERNS = [
        'ADR'  => '\bADR\b',
        'IATA' => '\bIATA\b|\ba(?:é|e)rien(?:ne)?s?\b',
        'IMDG' => '\bIMDG\b|\bmaritimes?\b',
    ];

    /**
     * Words of the text of a mail naming each category: the ones of a subject, plus the plane and the ship. Never the
     * road nor the truck: every transport has a road part, by air or by sea too
     */
    public const CATEGORY_BODY_PATTERNS = [
        'ADR'  => '\bADR\b',
        'IATA' => '\bIATA\b|\ba(?:é|e)rien(?:ne)?s?\b|\bavions?\b',
        'IMDG' => '\bIMDG\b|\bmaritimes?\b|\bbateaux?\b|\bnavires?\b',
    ];

    /**
     * Picto of a request by its category: the truck (road, and no category: the picto of the module), the plane, the ship
     */
    public const CATEGORY_PICTOS = ['ADR' => 'fa-truck-moving', 'IATA' => 'fa-plane', 'IMDG' => 'fa-ship'];

    /**
     * Countries of Europe (ISO 3166 codes), with the countries partly in Europe (Turkey, Russia, the Caucasus,
     * Kazakhstan): the road can go there. Any other country is reached by air or by sea, never by the ADR alone
     */
    public const EUROPE_COUNTRIES = [
        'AD', 'AL', 'AM', 'AT', 'AX', 'AZ', 'BA', 'BE', 'BG', 'BY', 'CH', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FO',
        'FR', 'GB', 'GE', 'GG', 'GI', 'GR', 'HR', 'HU', 'IE', 'IM', 'IS', 'IT', 'JE', 'KZ', 'LI', 'LT', 'LU', 'LV', 'MC',
        'MD', 'ME', 'MK', 'MT', 'NL', 'NO', 'PL', 'PT', 'RO', 'RS', 'RU', 'SE', 'SI', 'SJ', 'SK', 'SM', 'TR', 'UA', 'VA',
        'XK',
    ];

    /**
     * Names of countries the dictionary of Dolibarr does not have (short names, old names, parts of the world), with
     * their code: ZZ for a part of the world outside Europe
     */
    public const COUNTRY_ALIASES = [
        'USA' => 'US', 'U.S.A.' => 'US', 'Amérique' => 'ZZ', 'Amériques' => 'ZZ', 'America' => 'ZZ', 'Afrique' => 'ZZ',
        'Africa' => 'ZZ', 'Asie' => 'ZZ', 'Asia' => 'ZZ', 'Océanie' => 'ZZ', 'Oceania' => 'ZZ', 'Antilles' => 'ZZ',
        'DOM-TOM' => 'ZZ', 'DOM TOM' => 'ZZ', 'Outre-mer' => 'ZZ', 'Dubaï' => 'AE', 'Abu Dhabi' => 'AE', 'Emirats' => 'AE',
        'UAE' => 'AE', 'Corée' => 'KR', 'Korea' => 'KR', 'Taïwan' => 'TW', 'Vietnam' => 'VN', 'Birmanie' => 'MM',
        'Guyane' => 'GF', 'Polynésie' => 'PF', 'Tahiti' => 'PF', 'Ile Maurice' => 'MU', 'La Réunion' => 'RE', 'Ile de la Réunion' => 'RE', 'Russie' => 'RU', 'Tchéquie' => 'CZ',
        'Tchèque' => 'CZ', 'Angleterre' => 'GB', 'Ecosse' => 'GB', 'Grande-Bretagne' => 'GB', 'UK' => 'GB',
        'Hollande' => 'NL', 'Kosovo' => 'XK',
    ];

    /**
     * Names of countries that are also usual words, first names or places of France (« suite à la réunion »,
     * « Maurice », « incident bénin », « Saint-Martin »): read in a subject or the name of a document, never in the
     * text of a mail
     */
    public const COUNTRY_NAMES_TITLE_ONLY = [
        'REUNION', 'LA REUNION', 'MAURICE', 'DOMINIQUE', 'GRENADE', 'BENIN', 'SALVADOR', 'MONTSERRAT', 'SAINT MARTIN',
        'SAINTE HELENE', 'SAINT BARTHELEMY', 'SAINTE LUCIE', 'CHAD', 'JORDAN',
    ];

    /**
     * @var array Array with all fields and their property. Do not use it as a static var. It may be modified by constructor
     */
    public $fields = [
        'rowid'            => ['type' => 'integer',      'label' => 'TechnicalID',      'enabled' => 1, 'position' => 1,   'notnull' => 1, 'visible' => 0, 'noteditable' => 1, 'index' => 1, 'comment' => 'Id'],
        'ref'              => ['type' => 'varchar(128)', 'label' => 'Ref',              'enabled' => 1, 'position' => 10,  'notnull' => 1, 'visible' => 4, 'noteditable' => 1, 'default' => '(PROV)', 'index' => 1, 'searchall' => 1, 'showoncombobox' => 1, 'validate' => 1],
        'ref_ext'          => ['type' => 'varchar(128)', 'label' => 'RefExt',           'enabled' => 1, 'position' => 20,  'notnull' => 0, 'visible' => 0],
        'entity'           => ['type' => 'integer',      'label' => 'Entity',           'enabled' => 1, 'position' => 30,  'notnull' => 1, 'visible' => 0, 'index' => 1],
        // In the list: the request, its thirdparty, its state and ticks, the nomeref ID, then the mail
        // The empty key: the inline select of the list offers « none » (the filter of the list leaves it out)
        'category'         => ['type' => 'varchar(8)',   'label' => 'TmdCategory',      'enabled' => 1, 'position' => 35,  'notnull' => 0, 'visible' => 1, 'index' => 1, 'arrayofkeyval' => ['' => '', 'ADR' => 'ADR', 'IATA' => 'IATA', 'IMDG' => 'IMDG'], 'csslist' => 'center nowraponall', 'help' => 'TmdCategoryHelp'],
        'date_mail'        => ['type' => 'datetime',     'label' => 'TmdDateMail',     'enabled' => 1, 'position' => 40,  'notnull' => 0, 'visible' => 1, 'noedit' => 1, 'csslist' => 'nowraponall'],
        'date_due'         => ['type' => 'date',         'label' => 'TmdDateDue',       'enabled' => 1, 'position' => 45,  'notnull' => 0, 'visible' => 1, 'index' => 1, 'csslist' => 'nowraponall', 'help' => 'TmdDateDueHelp'],
        'fk_soc'           => ['type' => 'integer:Societe:societe/class/societe.class.php:1', 'label' => 'ThirdParty', 'picto' => 'company', 'enabled' => 'isModEnabled("societe")', 'position' => 50, 'notnull' => 0, 'visible' => 1, 'index' => 1, 'css' => 'maxwidth500 widthcentpercentminusxx', 'csslist' => 'tdoverflowmax150'],
        'fk_socpeople'     => ['type' => 'integer:Contact:contact/class/contact.class.php:1', 'label' => 'Contact', 'picto' => 'contact', 'enabled' => 'isModEnabled("societe")', 'position' => 55, 'notnull' => 0, 'visible' => -1, 'css' => 'maxwidth500 widthcentpercentminusxx', 'csslist' => 'tdoverflowmax150'],
        'status'           => ['type' => 'smallint',     'label' => 'TmdState',         'enabled' => 1, 'position' => 60,  'notnull' => 1, 'visible' => 5, 'default' => 0, 'index' => 1, 'arrayofkeyval' => [0 => 'TmdStatusPending', 3 => 'TmdStatusSubcontracted', 1 => 'TmdStatusDone', 2 => 'TmdStatusInvoiced'], 'searchmulti' => 1, 'csslist' => 'center nowraponall', 'help' => 'TmdStateHelp'],
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
     * @var string|null Category: ADR, IATA or IMDG (self::CATEGORIES), null when not known
     */
    public $category;

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
     * Load the values of a row (fetch, each row of the list): the picto follows the category of the request (banner,
     * link, tooltip)
     *
     * @param  object $obj Row of the database
     * @return void
     */
    public function setVarsFromFetchObj(&$obj)
    {
        parent::setVarsFromFetchObj($obj);
        $this->picto = self::categoryPicto($this->category);
    }

    /**
     * Picto of a category: the truck for the road and for no category, the plane for the air, the ship for the sea
     *
     * @param  mixed  $category Category of the request
     * @return string           Picto (fontawesome_…), the color of the module
     */
    public static function categoryPicto($category): string
    {
        return 'fontawesome_' . (self::CATEGORY_PICTOS[self::normalizeCategory($category) ?? 'ADR']) . '_fas_#E67E22';
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
        // Not chosen: the one the subject names, else its country (mail received, form left empty, API)
        $this->category = self::normalizeCategory($this->category) ?? self::normalizeCategory(self::categoryFromMail((string) $this->label, ''));

        // Deadline: the date of the mail (or today) plus the delay of the setup, 0 = no deadline
        $delay = getDolGlobalInt('DOLITMD_DELAY_DAYS', 15);
        if (empty($this->date_due) && $delay > 0) {
            $base           = !empty($this->date_mail) ? (is_numeric($this->date_mail) ? (int) $this->date_mail : (int) $this->db->jdate($this->date_mail)) : dol_now();
            $this->date_due = dol_time_plus_duree($base, $delay, 'd');
        }

        return parent::create($user, $noTrigger);
    }

    /**
     * The request is late: not done nor subcontracted, its deadline before today
     *
     * @return bool
     */
    public function isLate(): bool
    {
        if (!empty($this->done) || empty($this->date_due) || in_array((int) $this->status, [self::STATUS_DELETED, self::STATUS_SUBCONTRACTED], true)) {
            return false;
        }
        $due = is_numeric($this->date_due) ? (int) $this->date_due : (int) $this->db->jdate($this->date_due);

        return $due < dol_get_first_hour(dol_now());
    }

    /**
     * Number of late requests (not done nor subcontracted, deadline before today)
     *
     * @return int
     */
    public function countLate(): int
    {
        $obj = $this->db->getRow('SELECT COUNT(rowid) AS nb FROM ' . $this->db->prefix() . $this->table_element
            . ' WHERE entity IN (' . getEntity($this->element) . ') AND status >= 0 AND status <> ' . self::STATUS_SUBCONTRACTED . ' AND done = 0 AND date_due IS NOT NULL'
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
     * What the ticket of the request holds, read on the card: the mail of the request, then the messages of the ticket
     * (answers received, mails sent, notes), the oldest first. Each file of the ticket goes with the message saved
     * just before it (the mail handler saves the attachments right after the message)
     *
     * @return array<int,array{code:string,title:string,date:int,message:string,files:array<int,array{name:string,size:int,path:string}>}> Empty without ticket
     */
    public function getRequestThread(): array
    {
        global $conf, $langs;

        if (empty($this->fk_ticket) || !isModEnabled('ticket')) {
            return [];
        }
        require_once DOL_DOCUMENT_ROOT . '/ticket/class/ticket.class.php';
        require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

        $ticket = new Ticket($this->db);
        if ($ticket->fetch((int) $this->fk_ticket) <= 0) {
            return [];
        }

        $sender  = (string) ($this->email_from ?: $ticket->origin_email);
        $thread  = [['code' => 'dolitmd_request', 'title' => $sender !== '' ? $langs->trans('TmdRequestFrom', $sender) : $langs->trans('TmdRequestContent'), 'date' => (int) ($this->date_mail ?: $ticket->datec), 'message' => (string) $ticket->message, 'files' => []]];
        $created = [(int) $ticket->datec];

        // Label of a message: the subject of the ticket (cut after its first [...]), then who wrote it
        $prefix = trim((string) preg_replace('/(\[[^\]]*\]).*$/', '\1', (string) $ticket->subject));
        $sql    = 'SELECT a.id, a.code, a.label, a.note, a.datep, a.datec, a.fk_user_author FROM ' . $this->db->prefix() . 'actioncomm AS a';
        $sql   .= " WHERE a.elementtype = 'ticket' AND a.fk_element = " . ((int) $ticket->id) . ' AND a.entity IN (' . getEntity('agenda') . ')';
        $sql   .= " AND a.code IN ('TICKET_MSG', 'TICKET_MSG_SENTBYMAIL', 'TICKET_MSG_PRIVATE', 'TICKET_MSG_PRIVATE_SENTBYMAIL')";
        $sql   .= ' ORDER BY a.datep ASC, a.id ASC';
        $resql  = $this->db->query($sql);
        $users  = [];
        while ($resql && ($obj = $this->db->fetch_object($resql))) {
            $title = trim((string) $obj->label);
            if ($prefix !== '' && strpos($title, $prefix) === 0) {
                $title = trim(substr($title, strlen($prefix)));
            }
            if ($title === '') {
                $userId = (int) $obj->fk_user_author;
                if (!isset($users[$userId])) {
                    $author          = new User($this->db);
                    $users[$userId] = $userId > 0 && $author->fetch($userId) > 0 ? $author->getFullName($langs) : '';
                }
                $title = $users[$userId] !== '' ? $langs->trans('TmdThreadMessageBy', $users[$userId]) : $langs->trans('Message');
            }
            $thread[]  = ['code' => strtolower((string) $obj->code), 'title' => $title, 'date' => (int) $this->db->jdate($obj->datep), 'message' => (string) $obj->note, 'files' => []];
            $created[] = (int) $this->db->jdate($obj->datec);
        }

        $baseDir = $conf->ticket->multidir_output[$ticket->entity ?? $conf->entity] ?? $conf->ticket->dir_output;
        foreach (dol_dir_list($baseDir . '/' . dol_sanitizeFileName($ticket->ref), 'files', 0, '', '(\.meta|_preview.*\.png)$', 'date', SORT_ASC, 1) as $file) {
            if ($file['name'][0] === '.') {
                continue;
            }
            $index = 0;
            foreach ($created as $i => $date) {
                if ($date <= (int) $file['date']) {
                    $index = $i;
                }
            }
            $thread[$index]['files'][] = ['name' => (string) $file['name'], 'size' => (int) $file['size'], 'path' => dol_sanitizeFileName($ticket->ref) . '/' . $file['name']];
        }

        return $thread;
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
        // The empty choice of the select of the card is « 0 »
        $this->category = self::normalizeCategory($this->category);

        $result = parent::update($user, $noTrigger);
        // Done now, or pending again: the ticket follows (closed as solved, opened again). Never a mail: the mail
        // « request done » leaves from the button of the card only (TmdMailer::sendCardMail()), once read again
        if ($result > 0 && is_object($before) && (int) $before->done !== (int) $this->done) {
            $this->syncTicket($user);
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
     * A category of self::CATEGORIES, whatever its case, else null (empty, « 0 » of the select of the card, unknown)
     *
     * @param  mixed       $value Value given
     * @return string|null        ADR, IATA, IMDG or null
     */
    public static function normalizeCategory($value): ?string
    {
        $value = strtoupper(trim((string) $value));

        return in_array($value, self::CATEGORIES, true) ? $value : null;
    }

    /**
     * Category named by a text (the subject of the mail): ADR, IATA or IMDG, or the words air and sea in French. Several
     * named: the first one in the text
     *
     * @param  string               $text     Text read
     * @param  array<string,string> $patterns Words of each category (self::CATEGORY_PATTERNS, CATEGORY_BODY_PATTERNS)
     * @return string                         Category, empty when the text names none
     */
    public static function categoryFromText(string $text, array $patterns = self::CATEGORY_PATTERNS): string
    {
        $category = '';
        $first    = PHP_INT_MAX;
        foreach ($patterns as $key => $pattern) {
            if (preg_match('/' . $pattern . '/iu', $text, $matches, PREG_OFFSET_CAPTURE) && $matches[0][1] < $first) {
                $first    = $matches[0][1];
                $category = $key;
            }
        }

        return $category;
    }

    /**
     * Category named by a mail: its subject first, else its text (the words of the sender, not the mails it answers:
     * a new request written as an answer to the previous one would take its category). A forwarded mail: the mail
     * forwarded too, the request is there. None named: the country of the shipment (categoryFromCountry())
     *
     * @param  string $subject Subject of the mail
     * @param  string $body    Text of the mail
     * @return string          Category, empty when the mail names none
     */
    public static function categoryFromMail(string $subject, string $body): string
    {
        $category = self::categoryFromText($subject);
        if ($category !== '') {
            return $category;
        }
        [$own, $quoted] = self::splitQuotedText($body);
        $forwarded      = $quoted !== '' && preg_match('/^\s*(?:TR|FW|FWD|WG)\s*:/i', $subject);
        $category       = self::categoryFromText($own, self::CATEGORY_BODY_PATTERNS);
        if ($category === '' && $forwarded) {
            $category = self::categoryFromText($quoted, self::CATEGORY_BODY_PATTERNS);
        }

        return $category !== '' ? $category : self::categoryFromCountry($subject, $own . ($forwarded ? "\n" . $quoted : ''));
    }

    /**
     * Category given by the country of the shipment: outside Europe the road cannot go, it is not ADR but the air
     * (IATA) most of the time; a shipment by sea names its ship, read before (categoryFromText()). The countries named
     * by the title (subject of the mail, name of a document), else by the text. A title naming only countries of
     * Europe decides alone: nothing, the road or not, the text is not read (the address in a signature)
     *
     * @param  string $title Subject of the mail, name of a document
     * @param  string $text  Text of the mail (the words of the sender)
     * @return string        IATA, empty when no country outside Europe is named
     */
    public static function categoryFromCountry(string $title, string $text = ''): string
    {
        $countries = self::countriesNamed($title, true);
        if (empty($countries)) {
            $countries = self::countriesNamed($text);
        }

        return empty(array_diff($countries, self::EUROPE_COUNTRIES)) ? '' : 'IATA';
    }

    /**
     * Countries named by a text: their names in French and in English (dictionary of the countries of Dolibarr) and
     * COUNTRY_ALIASES, whatever the case and the accents, whole words only. The text of a mail: without the names of
     * COUNTRY_NAMES_TITLE_ONLY
     *
     * @param  string   $text  Text read
     * @param  bool     $title True for a subject or the name of a document
     * @return string[]        Codes of the countries named (ZZ: a part of the world outside Europe)
     */
    public static function countriesNamed(string $text, bool $title = false): array
    {
        $haystack = ' ' . self::normalizeCountryName($text) . ' ';
        if (trim($haystack) === '') {
            return [];
        }
        $codes = [];
        foreach (self::countryNames() as $name => $code) {
            if (($title || !in_array($name, self::COUNTRY_NAMES_TITLE_ONLY, true)) && strpos($haystack, ' ' . $name . ' ') !== false) {
                $codes[$code] = $code;
            }
        }

        return array_values($codes);
    }

    /**
     * Names of the countries, normalized (normalizeCountryName()), with their code: the dictionary of Dolibarr in
     * French and in English, then COUNTRY_ALIASES
     *
     * @return array<string,string> Code by name
     */
    protected static function countryNames(): array
    {
        global $conf;

        static $names = null;
        if ($names !== null) {
            return $names;
        }
        $names = [];
        foreach (['en_US', 'fr_FR'] as $lang) {
            $dictionary = new Translate('', $conf);
            $dictionary->setDefaultLang($lang);
            $dictionary->load('dict');
            foreach ($dictionary->tab_translate as $key => $label) {
                if (preg_match('/^Country([A-Z]{2})$/', $key, $matches) && ($name = self::normalizeCountryName((string) $label)) !== '') {
                    $names[$name] = $matches[1];
                }
            }
        }
        foreach (self::COUNTRY_ALIASES as $alias => $code) {
            $names[self::normalizeCountryName($alias)] = $code;
        }

        return $names;
    }

    /**
     * Text without HTML, accents and punctuation, in upper case, words separated by a space: « Etats-Unis » and
     * « ÉTATS UNIS » are the same
     *
     * @param  string $text Text
     * @return string       Normalized text
     */
    protected static function normalizeCountryName(string $text): string
    {
        $text = strtoupper(dol_string_unaccent(dol_string_nohtmltag($text, 0, 'UTF-8')));

        return trim((string) preg_replace('/[^A-Z0-9]+/', ' ', $text));
    }

    /**
     * Text of a mail cut in two: the words of the sender, then from its signature (« -- ») or the first mail quoted
     * (lines « > », « De : … Envoyé : … » of Outlook, « Le … a écrit : », « ----- Message d'origine ----- », line of
     * underscores)
     *
     * @param  string   $text Text of the mail
     * @return string[]       [words of the sender, the rest]
     */
    public static function splitQuotedText(string $text): array
    {
        $text    = str_replace(["\r\n", "\r"], "\n", $text);
        $pattern = '/^[ \t]*(?:>|--[ \t]*$|-{2,}[ \t]*(?:Original Message|Message d.origine|Forwarded message|Message transf)'
            . '|_{10,}[ \t]*$|(?:De|From)[ \t]*:[^\n]*\n(?:[^\n]*\n){0,3}?[ \t]*(?:Envoy|Sent|Date)[^\n:]{0,4}:'
            . '|(?:Le|On)[ \t][^\n]{5,250}(?:\n[^\n]{0,120})?(?:a [ée]crit|wrote)[ \t]*:)/imu';
        if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return [substr($text, 0, $matches[0][1]), substr($text, $matches[0][1])];
        }

        return [$text, ''];
    }

    /**
     * Requests without category: the one their mail names, subject then text, else its country (requests made before
     * the categories or before a rule: at the activation of the module and by the scheduled job). Never a trigger:
     * nothing else than the category changes
     *
     * @return int Requests given a category
     */
    public function fillMissingCategories(): int
    {
        $p      = $this->db->prefix();
        $ticket = isModEnabled('ticket');
        $rows   = $this->db->getRows('SELECT t.rowid, t.label' . ($ticket ? ', tk.message' : '') . ' FROM ' . $p . $this->table_element . ' AS t'
            . ($ticket ? ' LEFT JOIN ' . $p . 'ticket AS tk ON tk.rowid = t.fk_ticket' : '')
            . " WHERE (t.category IS NULL OR t.category = '') AND t.label IS NOT NULL LIMIT 100000");
        $filled = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $category = self::categoryFromMail((string) $row->label, (string) ($row->message ?? ''));
            if ($category !== '' && $this->db->query('UPDATE ' . $this->db->prefix() . $this->table_element . " SET category = '" . $this->db->escape($category) . "' WHERE rowid = " . ((int) $row->rowid))) {
                $filled++;
            }
        }

        return $filled;
    }

    /**
     * First letters of a nomeref ID: X generic, A ADR / ADN, R RID, M IMDG, I IATA, O OACI, U UN number, C customer,
     * P product, S substance, D waste, N not regulated, E exempted, T temporary, H history, G generic (N.O.S.)
     */
    public const SOFTWARE_REF_PREFIXES = 'XARMIOUCPSDNETHG';

    /**
     * nomeref ID in the name of a document: one of the first letters of SOFTWARE_REF_PREFIXES, maybe a second letter,
     * then 5 digits or more (« PB ADR FRANCE X1640869.pdf » → X1640869, « PB8 ADR ESPAGNE E0289516.pdf » → E0289516,
     * « PB8 ADR FRANCE EV00001658.pdf » → EV00001658). A UN number (UN1203) has 4 digits only: never taken
     *
     * @param  string $fileName Name of the file
     * @return string           ID in upper case, empty when the name has none
     */
    public static function softwareIdFromFileName(string $fileName): string
    {
        $pattern = '/(?<![A-Za-z0-9])[' . self::SOFTWARE_REF_PREFIXES . '][A-Z]?\d{5,}(?![0-9])/i';

        return preg_match($pattern, pathinfo($fileName, PATHINFO_FILENAME), $matches) ? strtoupper($matches[0]) : '';
    }

    /**
     * nomeref ID of a request taken from a document: the whole name of the file without its extension, when it has a
     * nomeref ID (softwareIdFromFileName()): « PB2 ADR BULGARIE X1667682.pdf » → PB2 ADR BULGARIE X1667682
     *
     * @param  string $fileName Name of the file
     * @return string           Name of the file without extension, empty when the name has no nomeref ID
     */
    public static function softwareRefFromFileName(string $fileName): string
    {
        if (self::softwareIdFromFileName($fileName) === '') {
            return '';
        }

        return dol_trunc(trim((string) preg_replace('/\s+/u', ' ', pathinfo($fileName, PATHINFO_FILENAME))), 128, 'right', 'UTF-8', 1);
    }

    /**
     * nomeref ID given by the previous rule (the ID alone, « EV00001672 »): replaced by the name of its document
     *
     * @param  string $softwareRef nomeref ID of a request
     * @return bool
     */
    protected static function isBareSoftwareId(string $softwareRef): bool
    {
        return (bool) preg_match('/^[' . self::SOFTWARE_REF_PREFIXES . '][A-Z]?\d{5,}$/i', trim($softwareRef));
    }

    /**
     * A document with a nomeref ID added to the request (tab Documents): its name fills the nomeref ID of the request
     * when it has none, or the ID alone of this document (previous rule), and its category (ADR, IATA, IMDG named in the
     * name) when it has none. An ID or a category already given is never changed
     *
     * @param  User   $user     User adding the document
     * @param  string $fileName Name of the file
     * @return bool             ID or category filled
     */
    public function fillSoftwareRefFromFile(User $user, string $fileName): bool
    {
        $softwareRef = self::softwareRefFromFileName($fileName);
        if ($this->id <= 0 || $softwareRef === '' || (int) $this->status === self::STATUS_DELETED) {
            return false;
        }
        $changed = false;
        $current = trim((string) $this->software_ref);
        if ($current === '' || (strcasecmp($current, self::softwareIdFromFileName($fileName)) === 0 && $current !== $softwareRef)) {
            $this->software_ref = $softwareRef;
            $changed            = true;
        }
        if (self::normalizeCategory($this->category) === null) {
            $name     = str_replace(['_', '-', '.'], ' ', pathinfo($fileName, PATHINFO_FILENAME));
            $category = self::categoryFromText($name) ?: self::categoryFromCountry($name);
            if ($category !== '') {
                $this->category = $category;
                $changed        = true;
            }
        }

        return $changed && $this->update($user) > 0;
    }

    /**
     * Request without nomeref ID or without category (or with the ID alone of the previous rule): taken from the
     * documents already in its tab Documents, the oldest document with a nomeref ID first. Without the trigger of the
     * document (added before this was done, or by another way than the tab): at the display of the card and by the
     * scheduled job
     *
     * @param  User $user User filling
     * @return bool       ID or category filled
     */
    public function fillSoftwareRefFromDocuments(User $user): bool
    {
        $softwareRef = trim((string) $this->software_ref);
        $complete    = $softwareRef !== '' && !self::isBareSoftwareId($softwareRef) && self::normalizeCategory($this->category) !== null;
        if ($this->id <= 0 || $complete || (int) $this->status === self::STATUS_DELETED) {
            return false;
        }
        $files = $this->getPublicFiles();
        usort($files, static function ($a, $b) {
            return $a['date'] <=> $b['date'];
        });
        foreach ($files as $file) {
            if (self::softwareIdFromFileName($file['name']) !== '') {
                return $this->fillSoftwareRefFromFile($user, $file['name']);
            }
        }

        return false;
    }

    /**
     * Requests still without nomeref ID or without category (or with the ID alone of the previous rule): taken from
     * the documents already in their tab Documents, the oldest document with a nomeref ID first (the documents added
     * before this was done, or by another way than the tab)
     *
     * @param  User $user  User of the scheduled job
     * @param  int  $limit Requests looked at, at most
     * @return int         Requests filled
     */
    public function fillMissingSoftwareRefs(User $user, int $limit = 200): int
    {
        $rows = $this->db->getRows('SELECT rowid FROM ' . $this->db->prefix() . $this->table_element . ' WHERE entity IN (' . getEntity($this->element) . ')'
            . ' AND status <> ' . self::STATUS_DELETED . " AND (software_ref IS NULL OR software_ref = '' OR category IS NULL OR category = ''"
            . " OR (CHAR_LENGTH(software_ref) <= 16 AND software_ref NOT LIKE '% %')) ORDER BY rowid DESC LIMIT " . max(1, $limit));
        $filled = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $tmd = new self($this->db);
            if ($tmd->fetch((int) $row->rowid) > 0 && $tmd->fillSoftwareRefFromDocuments($user)) {
                $filled++;
            }
        }

        return $filled;
    }

    /**
     * Choose the state, then save: the ticks follow (pending or subcontracted = none, done = done, invoiced = done and
     * invoiced)
     *
     * @param  User $user  User choosing
     * @param  int  $state A key of self::STATE_LABELS
     * @return int         Return integer < 0 if KO, > 0 if OK
     */
    public function setState(User $user, int $state): int
    {
        if (!$this->applyState($state)) {
            $this->error = 'BadState';
            return -1;
        }

        return $this->update($user);
    }

    /**
     * Give the request a state, not saved: its ticks, and the state itself (pending and subcontracted have the same
     * ticks, none)
     *
     * @param  int  $state A key of self::STATE_LABELS
     * @return bool        False when the state is not one
     */
    public function applyState(int $state): bool
    {
        if (!array_key_exists($state, self::STATE_LABELS)) {
            return false;
        }
        $this->done     = in_array($state, [self::STATUS_DONE, self::STATUS_INVOICED], true) ? 1 : 0;
        $this->invoiced = $state === self::STATUS_INVOICED ? 1 : 0;
        if ((int) $this->status !== self::STATUS_DELETED) {
            $this->status = $state;
        }

        return true;
    }

    /**
     * The request is in this state, ticks included
     *
     * @param  int  $state A key of self::STATE_LABELS
     * @return bool
     */
    public function hasState(int $state): bool
    {
        return array_key_exists($state, self::STATE_LABELS) && (int) $this->status === $state
            && (int) $this->done === (in_array($state, [self::STATUS_DONE, self::STATUS_INVOICED], true) ? 1 : 0)
            && (int) $this->invoiced === ($state === self::STATUS_INVOICED ? 1 : 0);
    }

    /**
     * The thirdparty of the sender, found or just created: on this request and on the other ones of the same address
     * still without thirdparty, and on their tickets
     *
     * @param  User  $user      User linking
     * @param  int   $socId     Thirdparty
     * @param  int   $contactId Contact with the address, 0 when none
     * @return int[]|false      Ids of the requests linked, false on error
     */
    public function linkSenderThirdparty(User $user, int $socId, int $contactId = 0)
    {
        $p     = $this->db->prefix();
        $email = strtolower(trim((string) $this->email_from));
        $sql   = 'SELECT rowid, fk_ticket FROM ' . $p . $this->table_element . ' WHERE entity IN (' . getEntity($this->element) . ')';
        $sql  .= ' AND status <> ' . self::STATUS_DELETED . ' AND (fk_soc IS NULL OR fk_soc = 0)';
        $sql  .= ' AND (rowid = ' . ((int) $this->id) . ($email !== '' ? " OR LOWER(email_from) = '" . $this->db->escape($email) . "'" : '') . ')';
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return false;
        }
        $ids     = [];
        $tickets = [];
        while ($obj = $this->db->fetch_object($resql)) {
            $ids[] = (int) $obj->rowid;
            if ((int) $obj->fk_ticket > 0) {
                $tickets[] = (int) $obj->fk_ticket;
            }
        }
        if (empty($ids)) {
            return [];
        }

        $this->db->begin();
        $sql  = 'UPDATE ' . $p . $this->table_element . ' SET fk_soc = ' . $socId . ', fk_user_modif = ' . ((int) $user->id);
        $sql .= $contactId > 0 ? ', fk_socpeople = CASE WHEN fk_socpeople IS NULL OR fk_socpeople = 0 THEN ' . $contactId . ' ELSE fk_socpeople END' : '';
        $sql .= ' WHERE rowid IN (' . implode(',', $ids) . ')';
        $ok   = (bool) $this->db->query($sql);
        // A ticket already given a thirdparty by hand keeps it
        if ($ok && !empty($tickets) && isModEnabled('ticket')) {
            $ok = (bool) $this->db->query('UPDATE ' . $p . 'ticket SET fk_soc = ' . $socId . ' WHERE rowid IN (' . implode(',', $tickets) . ') AND (fk_soc IS NULL OR fk_soc = 0)');
        }
        if (!$ok) {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            return false;
        }
        $this->db->commit();

        if (in_array((int) $this->id, $ids, true)) {
            $this->fk_soc = $socId;
        }

        return $ids;
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
        // Neither ticked: subcontracted stays so, anything else is pending
        if ((int) $this->status !== self::STATUS_DELETED) {
            $this->status = $this->invoiced ? self::STATUS_INVOICED : ($this->done ? self::STATUS_DONE : ((int) $this->status === self::STATUS_SUBCONTRACTED ? self::STATUS_SUBCONTRACTED : self::STATUS_PENDING));
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
        // Unknown sender: its address and the button that creates its thirdparty, as in the list
        if ($key === 'fk_soc' && $this->id > 0 && empty($this->fk_soc) && !empty($this->email_from)) {
            require_once __DIR__ . '/../lib/dolitmd.lib.php';
            return dolitmd_sender_html($this, dolitmd_can_create_thirdparty($user));
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
            self::STATUS_DELETED       => ['Deleted', 'status9'],
            // Amber to do, violet at a subcontractor, green done (to invoice), grey closed: the colours of the cards above
            // the list (violet: a class of DoliTMD, no badge of Dolibarr has it)
            self::STATUS_PENDING       => ['TmdStatusPending', 'status1'],
            self::STATUS_SUBCONTRACTED => ['TmdStatusSubcontracted', 'status5', 'dolitmd-badge-subcontracted'],
            self::STATUS_DONE          => ['TmdStatusDone', 'status4'],
            self::STATUS_INVOICED      => ['TmdStatusInvoiced', 'status6'],
        ];
        $info  = $labels[$status] ?? $labels[self::STATUS_PENDING];
        $label = $langs->transnoentitiesnoconv($info[0]);

        return dolGetStatus($label, $label, '', $info[1], $mode, '', !empty($info[2]) ? ['badgeParams' => ['css' => $info[2]]] : []);
    }

    /**
     * Count the requests per state, for the cards above the list
     *
     * @return array<int,int> State => number of requests
     */
    public function countByStatus(): array
    {
        $out = [self::STATUS_PENDING => 0, self::STATUS_SUBCONTRACTED => 0, self::STATUS_DONE => 0, self::STATUS_INVOICED => 0];
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
