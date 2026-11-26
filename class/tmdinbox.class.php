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
 * \file    class/tmdinbox.class.php
 * \ingroup dolitmd
 * \brief   The TMD requests in the mails of Inbox: block « TMD » of the panel of a mail, « Link to a TMD request »
 */

// Load DoliTMD libraries
require_once __DIR__ . '/tmd.class.php';
require_once __DIR__ . '/tmdmailhandler.class.php';

/**
 * Class TmdInbox
 *
 * Inbox knows only the pieces of the core for its links: a request shows in the panel of a mail through the hooks of
 * Inbox for the modules (inboxMailPanels, inboxMailActions), its mail linked to the ticket of the request. Only public
 * functions of Inbox, checked before use: the version of Inbox changes from an instance to another.
 */
class TmdInbox
{
    /**
     * Requests offered in the list of « Link to a TMD request » at most
     */
    public const MAX_CHOICES = 300;

    /**
     * @var DoliDB Database handler
     */
    public $db;

    /**
     * @var array<int,array{id:int,in:bool}> What each mail is to the requests, for the hooks called on the same mail
     */
    protected static $relations = [];

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
     * Mail of the index of Inbox, when the user reads its mailbox (as Inbox: no level on the mailbox, no mail)
     *
     * @param  int         $mailId Row of llx_inbox_mail
     * @param  User        $user   User
     * @return object|null         Row (as TmdMailHandler::mailRow() reads it, and the address of its mailbox: account_email)
     */
    public function mailRow(int $mailId, User $user): ?object
    {
        global $conf;

        if ($mailId <= 0 || !isModEnabled('inbox') || !empty($user->socid) || !$user->hasRight('inbox', 'message', 'read')) {
            return null;
        }
        if (!dol_include_once('/inbox/class/inboxaccess.class.php') || !class_exists('InboxAccess') || !method_exists('InboxAccess', 'check')) {
            return null;
        }
        $p   = $this->db->prefix();
        $sql = 'SELECT m.rowid, m.fk_account, m.message_id, m.in_reply_to, m.refs, m.date_mail, m.date_received, m.from_email, m.from_name, m.to_text, m.cc_text, m.subject, m.snippet, m.requester_email,';
        $sql .= ' a.email AS account_email FROM ' . $p . 'inbox_mail AS m INNER JOIN ' . $p . 'inbox_account AS a ON a.rowid = m.fk_account';
        $sql .= ' WHERE m.rowid = ' . $mailId . ' AND a.entity = ' . ((int) $conf->entity);
        $row = $this->db->getRow($sql);
        if (!is_object($row) || !(new InboxAccess($this->db))->check($user, (int) $row->fk_account, InboxAccess::LEVEL_READ)) {
            return null;
        }

        return $row;
    }

    /**
     * What a mail is to the requests: the request it is in, else the request of its thread its subject is about (an
     * answer not taken: the TMD mailbox in copy only, a mail of another mailbox, a mail sent from the mailbox)
     *
     * @param  object $row Row of llx_inbox_mail
     * @return array{id:int,in:bool} Id of the request (0: none), in: the mail is in it
     */
    public function relationOf(object $row): array
    {
        $mailId = (int) $row->rowid;
        if (!isset(self::$relations[$mailId])) {
            $handler = new TmdMailHandler($this->db);
            $id      = $handler->requestOfMail((string) $row->message_id);
            if ($id > 0) {
                self::$relations[$mailId] = ['id' => $id, 'in' => true];
            } else {
                self::$relations[$mailId] = ['id' => $handler->findParentRequest($row), 'in' => false];
            }
        }

        return self::$relations[$mailId];
    }

    /**
     * Summary of the block « TMD » of the panel of a mail (JSON read by Inbox): the request, its state, its deadline,
     * and « Add to this request » for a mail of its thread that is not in it
     *
     * @param  object $row  Row of llx_inbox_mail (mailRow())
     * @param  User   $user User
     * @return array        badges, lines, action, links; hidden when the mail is not about a request
     */
    public function summary(object $row, User $user): array
    {
        global $langs;

        $langs->loadLangs(['companies', 'dolitmd@dolitmd']);
        $relation = $this->relationOf($row);
        $tmd      = new Tmd($this->db);
        if ($relation['id'] <= 0 || $tmd->fetch($relation['id']) <= 0) {
            return ['hidden' => true, 'lines' => [$langs->transnoentities('TmdInboxNoRequest')]];
        }

        $state  = (int) $tmd->status;
        $levels = [Tmd::STATUS_PENDING => 'warning', Tmd::STATUS_SUBCONTRACTED => 'info', Tmd::STATUS_DONE => 'ok', Tmd::STATUS_INVOICED => 'ok', Tmd::STATUS_ERRONEOUS => 'neutral'];
        $badges = [['label' => $langs->transnoentities(Tmd::STATE_LABELS[$state] ?? 'TmdStatusPending'), 'level' => $levels[$state] ?? 'neutral']];
        if ($tmd->isLate()) {
            $badges[] = ['label' => $langs->transnoentities('TmdLate'), 'level' => 'danger'];
        }
        if (!empty($tmd->category)) {
            $badges[] = ['label' => (string) $tmd->category, 'level' => 'neutral'];
        }

        $lines = [$tmd->ref . ' · ' . dol_trunc((string) $tmd->label, 80, 'right', 'UTF-8', 1)];
        if (!empty($tmd->fk_soc) && isModEnabled('societe') && $user->hasRight('societe', 'lire')) {
            $soc = new Societe($this->db);
            if ($soc->fetch((int) $tmd->fk_soc) > 0) {
                $lines[] = $langs->transnoentities('ThirdParty') . ' : ' . $soc->name;
            }
        }
        // The deadline of a request still to do only
        if (!empty($tmd->date_due) && empty($tmd->done) && !in_array($state, Tmd::STATES_ASIDE, true)) {
            $due     = is_numeric($tmd->date_due) ? (int) $tmd->date_due : (int) $this->db->jdate($tmd->date_due);
            $lines[] = $langs->transnoentities('TmdDateDue') . ' : ' . dol_print_date($due, 'day', 'tzuserrel');
        }

        $out = ['badges' => $badges, 'links' => [['label' => $langs->transnoentities('TmdInboxOpenRequest'), 'url' => dol_buildpath('/dolitmd/view/tmd/tmd_card.php', 1) . '?id=' . ((int) $tmd->id)]]];
        if (!$relation['in']) {
            // Mails of the TMD mailbox in its thread: already messages of its ticket, sent from the card
            if ((new TmdMailHandler($this->db))->isTmdAddress((string) $row->from_email)) {
                $lines[] = $langs->transnoentities('TmdInboxThreadOfRequest');
            } else {
                $lines[] = $langs->transnoentities('TmdInboxMailNotInRequest');
                if ($user->hasRight('dolitmd', 'tmd', 'write')) {
                    $out['action'] = ['label' => $langs->transnoentities('TmdInboxAddToRequest'), 'title' => $langs->transnoentities('TmdInboxLinkTitle'), 'url' => self::linkUrl((int) $row->rowid)];
                }
            }
        }
        $out['lines'] = array_slice($lines, 0, 4);

        return $out;
    }

    /**
     * Address of the page « Link to a TMD request » of a mail, in a window of Inbox (no menus of Dolibarr)
     *
     * @param  int    $mailId Row of llx_inbox_mail
     * @return string         Address inside Dolibarr
     */
    public static function linkUrl(int $mailId): string
    {
        return dol_buildpath('/dolitmd/view/tmd/tmd_inbox_link.php', 1) . '?mail=' . $mailId . '&dol_hide_topmenu=1&dol_hide_leftmenu=1';
    }

    /**
     * Requests offered to link a mail to: the request of its thread first, then the ones of its sender or of his
     * thirdparty, then the others; the newest first, the deleted ones never
     *
     * @param  object $row       Row of llx_inbox_mail (mailRow())
     * @param  int    $preferred Request offered first (the one of the thread), 0: none
     * @return array<int,array{id:int,label:string,suggested:bool}> In the order to show
     */
    public function choices(object $row, int $preferred = 0): array
    {
        global $langs;

        $p      = $this->db->prefix();
        $sender = strtolower(trim((string) (($row->requester_email ?? '') ?: ($row->from_email ?? ''))));
        $socId  = (new TmdMailHandler($this->db))->findSender($sender)[0];

        $near   = [];
        $near[] = $preferred > 0 ? 't.rowid = ' . $preferred : '1 = 0';
        $near[] = $sender !== '' ? "LOWER(t.email_from) = '" . $this->db->escape($sender) . "'" : '1 = 0';
        $near[] = $socId > 0 ? 't.fk_soc = ' . ((int) $socId) : '1 = 0';

        $sql  = 'SELECT t.rowid, t.ref, t.label, t.status, t.email_from, s.nom AS soc_name,';
        $sql .= ' CASE WHEN ' . $near[0] . ' THEN 3 WHEN ' . $near[1] . ' THEN 2 WHEN ' . $near[2] . ' THEN 1 ELSE 0 END AS score';
        $sql .= ' FROM ' . $p . 'dolitmd_tmd AS t LEFT JOIN ' . $p . 'societe AS s ON s.rowid = t.fk_soc';
        $sql .= ' WHERE t.entity IN (' . getEntity('tmd') . ') AND t.status >= 0';
        $sql .= ' ORDER BY score DESC, COALESCE(t.date_mail, t.date_creation) DESC, t.rowid DESC LIMIT ' . self::MAX_CHOICES;
        $rows = $this->db->getRows($sql);

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $obj) {
            $label = $obj->ref . ' — ' . dol_trunc((string) $obj->label, 70, 'right', 'UTF-8', 1);
            $who   = trim((string) ($obj->soc_name ?: $obj->email_from));
            if ($who !== '') {
                $label .= ' — ' . $who;
            }
            $label .= ' (' . $langs->transnoentities(Tmd::STATE_LABELS[(int) $obj->status] ?? 'TmdStatusPending') . ')';
            $out[]  = ['id' => (int) $obj->rowid, 'label' => $label, 'suggested' => (int) $obj->score > 0];
        }

        return $out;
    }
}
