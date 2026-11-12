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
 * \file    class/tmdmailer.class.php
 * \ingroup dolitmd
 * \brief   Automatic mails of the TMD requests: receipt and done to the sender, late requests to the person assigned
 */

// Load Dolibarr libraries
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/ticket/class/ticket.class.php';

// Load DoliTMD libraries
require_once __DIR__ . '/tmd.class.php';

/**
 * Class TmdMailer
 *
 * Each mail is off until switched on in the setup, and written from its template (Setup > Emails > Templates, type
 * « TMD requests »). Sent by the server of the TMD mailbox (module Inbox) when there is one: the sender sees the TMD
 * address, his answer comes back in the same conversation (In-Reply-To). Else by the mail setup of Dolibarr.
 * The nomeref ID is internal: never offered to the templates.
 */
class TmdMailer
{
    /**
     * Type of the templates of the module in llx_c_email_templates
     */
    public const TEMPLATE_TYPE = 'dolitmd_tmd';

    /**
     * The mails: constant switching them on, label of their template
     */
    public const MAILS = [
        'ack'  => ['const' => 'DOLITMD_MAIL_ACK', 'template' => '(TmdMailAckTemplate)'],
        'done' => ['const' => 'DOLITMD_MAIL_DONE', 'template' => '(TmdMailDoneTemplate)'],
        'late' => ['const' => 'DOLITMD_MAIL_LATE', 'template' => '(TmdMailLateTemplate)'],
    ];

    /**
     * @var DoliDB Database handler
     */
    public $db;

    /**
     * @var string Error
     */
    public $error = '';

    /**
     * @var string Output of the scheduled job
     */
    public $output = '';

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
     * Receipt to the sender of a request received by mail
     *
     * @param  Tmd  $tmd  TMD request
     * @param  User $user User running the reception
     * @return bool       Sent
     */
    public function sendAck(Tmd $tmd, User $user): bool
    {
        return $this->sendToSender('ack', $tmd, $user, 'auto-replied');
    }

    /**
     * Mail to the sender of a request now done
     *
     * @param  Tmd  $tmd  TMD request
     * @param  User $user User ticking done
     * @return bool       Sent
     */
    public function sendDone(Tmd $tmd, User $user): bool
    {
        return $this->sendToSender('done', $tmd, $user, 'auto-generated');
    }

    /**
     * Scheduled job, every day: each person assigned gets the list of his late requests
     *
     * @return int 0 if OK, < 0 if KO
     */
    public function cronLateReminder(): int
    {
        global $langs;

        $this->output = '';
        if (!getDolGlobalInt(self::MAILS['late']['const'])) {
            $this->output = 'Reminder of the late requests switched off';
            return 0;
        }

        $langs->load('dolitmd@dolitmd');
        $p    = $this->db->prefix();
        $sql  = 'SELECT t.rowid, k.fk_user_assign FROM ' . $p . 'dolitmd_tmd AS t LEFT JOIN ' . $p . 'ticket AS k ON k.rowid = t.fk_ticket';
        $sql .= ' WHERE t.entity IN (' . getEntity('tmd') . ') AND t.status >= 0 AND t.done = 0 AND t.date_due IS NOT NULL';
        $sql .= " AND t.date_due < '" . $this->db->idate(dol_get_first_hour(dol_now())) . "' ORDER BY t.date_due ASC LIMIT 500";
        $rows = $this->db->getRows($sql);

        $perUser = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $userId = (int) $row->fk_user_assign > 0 ? (int) $row->fk_user_assign : getDolGlobalInt('DOLITMD_TICKET_USER');
            if ($userId > 0) {
                $perUser[$userId][] = (int) $row->rowid;
            }
        }

        $sent = 0;
        foreach ($perUser as $userId => $ids) {
            $assigned = new User($this->db);
            if ($assigned->fetch($userId) <= 0 || empty($assigned->statut) || !isValidEmail((string) $assigned->email)) {
                continue;
            }
            if ($this->sendLateList($assigned, $ids)) {
                $sent++;
            }
        }
        $this->output = $sent . ' reminder(s) sent' . ($this->error ? ', error: ' . $this->error : '');

        return $this->error ? -1 : 0;
    }

    /**
     * Template of a mail, in the entity first
     *
     * @param  string     $mail ack, done or late
     * @return array|null       [topic, content], null when missing or disabled
     */
    public function template(string $mail): ?array
    {
        $sql  = 'SELECT topic, content FROM ' . $this->db->prefix() . 'c_email_templates';
        $sql .= " WHERE type_template = '" . $this->db->escape(self::TEMPLATE_TYPE) . "' AND label = '" . $this->db->escape(self::MAILS[$mail]['template']) . "'";
        $sql .= " AND active = 1 AND entity IN (0, " . getEntity('c_email_templates') . ') ORDER BY entity DESC, position ASC';
        $row = $this->db->getRow($sql);

        return is_object($row) ? [(string) $row->topic, (string) $row->content] : null;
    }

    /**
     * Keys of the templates of a request (never the nomeref ID)
     *
     * @param  Tmd       $tmd    TMD request
     * @param  Ticket|null $ticket Its ticket
     * @return array<string,string>
     */
    public function substitutions(Tmd $tmd, ?Ticket $ticket): array
    {
        global $langs;

        $thirdparty = '';
        if ($tmd->fk_soc > 0) {
            $soc = new Societe($this->db);
            if ($soc->fetch((int) $tmd->fk_soc) > 0) {
                $thirdparty = (string) $soc->name;
            }
        }
        $db   = $this->db;
        $date = static function ($value, string $format) use ($db) {
            return empty($value) ? '' : dol_print_date(is_numeric($value) ? (int) $value : $db->jdate($value), $format, 'tzserver');
        };

        return array_merge(getCommonSubstitutionArray($langs, 0, null, $tmd), [
            '__TMD_REF__'           => (string) $tmd->ref,
            '__TMD_SUBJECT__'       => (string) $tmd->label,
            '__TMD_DATE_RECEIVED__' => $date($tmd->date_mail, 'dayhour'),
            '__TMD_DATE_DUE__'      => $date($tmd->date_due, 'day'),
            '__TICKET_REF__'        => $ticket ? (string) $ticket->ref : '',
            '__THIRDPARTY_NAME__'   => $thirdparty,
            '__SENDER_EMAIL__'      => (string) $tmd->email_from,
            // Public page of the request: where it stands, its documents
            '__TMD_TRACK_ID__'      => $tmd->ensureTrackId(),
            '__TMD_PUBLIC_URL__'    => $tmd->getPublicUrl(),
        ]);
    }

    /**
     * Receipt or done to the sender, in the conversation of his mail, written in the messages of the ticket
     *
     * @param  string $mail          ack or done
     * @param  Tmd    $tmd           TMD request
     * @param  User   $user          User
     * @param  string $autoSubmitted Header Auto-Submitted (RFC 3834: no answer of an absence robot to it)
     * @return bool                  Sent
     */
    protected function sendToSender(string $mail, Tmd $tmd, User $user, string $autoSubmitted): bool
    {
        global $langs;

        if (!getDolGlobalInt(self::MAILS[$mail]['const']) || !isValidEmail((string) $tmd->email_from)) {
            return false;
        }
        $template = $this->template($mail);
        if ($template === null) {
            $this->error = 'Template ' . self::MAILS[$mail]['template'] . ' not found';
            dol_syslog(__METHOD__ . ' ' . $this->error, LOG_WARNING);
            return false;
        }

        $langs->load('dolitmd@dolitmd');
        $ticket = null;
        if ($tmd->fk_ticket > 0) {
            $ticket = new Ticket($this->db);
            if ($ticket->fetch((int) $tmd->fk_ticket) <= 0) {
                $ticket = null;
            }
        }
        [$subject, $html] = $this->compose($template, $this->substitutions($tmd, $ticket));

        $headers = ['Auto-Submitted' => $autoSubmitted, 'X-Auto-Response-Suppress' => 'All'];
        if (!empty($tmd->message_id)) {
            $headers['In-Reply-To'] = '<' . $tmd->message_id . '>';
            $headers['References']  = '<' . $tmd->message_id . '>';
        }
        $ok = $this->send((string) $tmd->email_from, $subject, $html, $headers, $ticket);

        // In the messages of the ticket: what the sender got, and when
        if ($ok && $ticket) {
            $ticket->message = $this->text($html);
            $ticket->private = 0;
            $ticket->createTicketMessage($user, 1, [], [], [], true, 0, $langs->transnoentities($mail == 'ack' ? 'TmdMailAckSent' : 'TmdMailDoneSent', $tmd->email_from));
        }

        return $ok;
    }

    /**
     * Late requests of a person assigned
     *
     * @param  User  $assigned Person assigned
     * @param  int[] $ids      Late requests
     * @return bool            Sent
     */
    protected function sendLateList(User $assigned, array $ids): bool
    {
        global $langs;

        $template = $this->template('late');
        if ($template === null) {
            $this->error = 'Template ' . self::MAILS['late']['template'] . ' not found';
            return false;
        }

        $lines = '';
        foreach ($ids as $id) {
            $tmd = new Tmd($this->db);
            if ($tmd->fetch($id) <= 0) {
                continue;
            }
            $url    = dol_buildpath('/dolitmd/view/tmd/tmd_card.php', 2) . '?id=' . $tmd->id;
            $due    = dol_print_date($this->db->jdate($tmd->date_due), 'day', 'tzserver');
            $lines .= '<li><a href="' . dol_escape_htmltag($url) . '">' . dol_escape_htmltag($tmd->ref) . '</a> — ' . dol_escape_htmltag((string) $tmd->label)
                . ' (' . dol_escape_htmltag($langs->transnoentities('TmdDateDue')) . ' : ' . dol_escape_htmltag($due) . ')</li>';
        }
        if ($lines === '') {
            return false;
        }

        $substitutions = array_merge(getCommonSubstitutionArray($langs), [
            '__TMD_LATE_COUNT__' => (string) count($ids),
            '__TMD_LATE_LIST__'  => '<ul>' . $lines . '</ul>',
            '__TMD_LIST_URL__'   => dol_buildpath('/saturne/view/saturne_list.php', 2) . '?object_type=tmd&mainmenu=dolitmd',
            '__USER_FIRSTNAME__' => (string) $assigned->firstname,
        ]);
        [$subject, $html] = $this->compose($template, $substitutions, ['__TMD_LATE_LIST__']);

        return $this->send((string) $assigned->email, $subject, $html, ['Auto-Submitted' => 'auto-generated', 'X-Auto-Response-Suppress' => 'All']);
    }

    /**
     * Subject and HTML of a template, keys replaced (escaped in the HTML, except the keys already in HTML)
     *
     * @param  array    $template      [topic, content]
     * @param  array    $substitutions Keys
     * @param  string[] $htmlKeys      Keys whose value is HTML already
     * @return string[]                [subject, html]
     */
    protected function compose(array $template, array $substitutions, array $htmlKeys = []): array
    {
        global $langs;

        $escaped = [];
        foreach ($substitutions as $key => $value) {
            $escaped[$key] = in_array($key, $htmlKeys, true) ? (string) $value : dol_escape_htmltag((string) $value);
        }
        $subject = trim(preg_replace('/[\r\n]+/', ' ', make_substitutions($template[0], $substitutions, $langs)));
        $html    = make_substitutions($template[1], $escaped, $langs);
        if (!dol_textishtml($html)) {
            $html = dol_nl2br($html);
        }

        return [$subject, $html];
    }

    /**
     * Text version of the HTML of a mail
     *
     * @param  string $html HTML
     * @return string
     */
    protected function text(string $html): string
    {
        $text = preg_replace('/<(br|\/p|\/div|\/li|\/tr|\/h[1-6])\b[^>]*>/i', "\n", $html);

        return trim(html_entity_decode(dol_string_nohtmltag($text, 0), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Send: by the server of the TMD mailbox (Inbox), else by the mail setup of Dolibarr
     *
     * @param  string               $to      Recipient
     * @param  string               $subject Subject
     * @param  string               $html    HTML
     * @param  array<string,string> $headers More headers
     * @param  Ticket|null          $ticket  Ticket (track id of Dolibarr)
     * @return bool
     */
    protected function send(string $to, string $subject, string $html, array $headers, ?Ticket $ticket = null): bool
    {
        global $conf;

        $this->error = '';
        $text        = $this->text($html);
        $page        = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';

        require_once __DIR__ . '/tmdmailhandler.class.php';
        $mailbox = TmdMailHandler::configuredMailbox($this->db);
        if ($mailbox !== null && (int) $mailbox->status === 1 && dol_include_once('/inbox/class/inboxsystemmail.class.php') && dol_include_once('/inbox/class/inboxaccount.class.php') && class_exists('InboxSystemMail')) {
            $account = new InboxAccount($this->db);
            if ($account->fetch((int) $mailbox->rowid) > 0) {
                $boundary = 'dolitmd' . md5(uniqid('', true));
                [$contentType, $body] = InboxSystemMail::multipart('multipart/alternative', [
                    InboxSystemMail::part('text/plain; charset=UTF-8', $text),
                    InboxSystemMail::part('text/html; charset=UTF-8', $page),
                ], $boundary);
                $from   = ['email' => (string) $account->email, 'name' => (string) ($account->label && $account->label !== $account->email ? $account->label : getDolGlobalString('MAIN_INFO_SOCIETE_NOM'))];
                $sender = new InboxSystemMail();
                if ($sender->send($this->db, $account, $from, $to, $subject, $contentType, $body, $headers)) {
                    return true;
                }
                $this->error = $sender->errorCode . ' ' . $sender->error;
                dol_syslog(__METHOD__ . ' mail to ' . $to . ' not sent by the TMD mailbox: ' . $this->error, LOG_WARNING);
                return false;
            }
        }

        // No TMD mailbox: the mail setup of Dolibarr
        require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
        $more = '';
        foreach (['Auto-Submitted', 'X-Auto-Response-Suppress'] as $name) {
            if (!empty($headers[$name])) {
                $more .= $name . ': ' . $headers[$name] . "\r\n";
            }
        }
        $from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
        $mail = new CMailFile($subject, $to, $from, $page, [], [], [], '', '', 0, 1, '', '', $ticket ? 'tic' . $ticket->id : '', $more, 'standard', '', '', trim($headers['In-Reply-To'] ?? '', '<>'), trim($headers['References'] ?? '', '<>'));
        if ($mail->sendfile()) {
            return true;
        }
        $this->error = (string) $mail->error;
        dol_syslog(__METHOD__ . ' mail to ' . $to . ' not sent: ' . $this->error, LOG_WARNING);

        return false;
    }
}
