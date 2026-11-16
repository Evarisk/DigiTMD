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
 * \file    class/api_dolitmd.class.php
 * \ingroup dolitmd
 * \brief   REST API of DoliTMD: the TMD requests (list, read, create, change, state, mail « request done », delete)
 */

use Luracast\Restler\RestException;

require_once __DIR__ . '/tmd.class.php';

/**
 * API of the TMD requests, at /api/index.php/dolitmd. Every change goes through the same methods as the pages: the
 * ticket of the request follows its state, the mail « request done » goes to the sender and the people in copy.
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Dolitmd extends DolibarrApi
{
    /**
     * Fields a request takes from the API (the others are computed or technical)
     */
    public const WRITABLE_FIELDS = ['label', 'ref_ext', 'date_mail', 'date_due', 'fk_soc', 'fk_socpeople', 'software_ref', 'email_from', 'fk_ticket'];

    /**
     * States of a request by their name
     */
    public const STATES = ['pending' => Tmd::STATUS_PENDING, 'done' => Tmd::STATUS_DONE, 'invoiced' => Tmd::STATUS_INVOICED];

    /**
     * @var Tmd $tmd {@type Tmd}
     */
    public $tmd;

    /**
     * Constructor
     */
    public function __construct()
    {
        global $db;

        $this->db  = $db;
        $this->tmd = new Tmd($this->db);
    }

    /**
     * Get a TMD request
     *
     * Besides its fields: state (pending, done or invoiced), late (deadline passed and not done), public_url (tracking
     * page of the customer).
     *
     * @param  int   $id ID of the request
     * @return array|mixed Data of the request
     *
     * @throws RestException 403 Forbidden
     * @throws RestException 404 Not found
     */
    public function get(int $id)
    {
        $this->checkRight('read');
        $this->fetchTmd($id);

        return $this->output($this->tmd);
    }

    /**
     * Get a TMD request by its reference
     *
     * @param  string $ref Reference of the request (TMD2611-0001)
     * @return array|mixed Data of the request
     *
     * @url GET ref/{ref}
     *
     * @throws RestException 403 Forbidden
     * @throws RestException 404 Not found
     */
    public function getByRef(string $ref)
    {
        $this->checkRight('read');
        if ($this->tmd->fetch(0, $ref) <= 0 || (int) $this->tmd->status === Tmd::STATUS_DELETED) {
            throw new RestException(404, 'TMD request not found');
        }
        $this->checkAccess();

        return $this->output($this->tmd);
    }

    /**
     * List the TMD requests
     *
     * @param  string $sortfield  Sort field (default: t.date_mail)
     * @param  string $sortorder  Sort order: ASC or DESC (default: DESC)
     * @param  int    $limit      Maximum number of results (default: 100)
     * @param  int    $page       Page number, from 0
     * @param  string $state      Only the requests in this state: pending, done, invoiced, or late (deadline passed and not done)
     * @param  int    $thirdparty Only the requests of this thirdparty (ID)
     * @param  string $sqlfilters Other criteria. Example: "(t.email_from:like:'%@staci.com')"
     * @param  string $properties Properties to return, separated by commas. Empty = all
     * @return array              List of requests
     *
     * @throws RestException 400 Bad request
     * @throws RestException 403 Forbidden
     * @throws RestException 503 Query error
     */
    public function index(string $sortfield = 't.date_mail', string $sortorder = 'DESC', int $limit = 100, int $page = 0, string $state = '', int $thirdparty = 0, string $sqlfilters = '', string $properties = ''): array
    {
        $this->checkRight('read');

        $sql  = 'SELECT t.rowid FROM ' . $this->db->prefix() . 'dolitmd_tmd AS t';
        $sql .= ' WHERE t.entity IN (' . getEntity('tmd') . ') AND t.status >= 0';
        if ($state !== '') {
            if ($state === 'late') {
                $sql .= " AND t.done = 0 AND t.date_due IS NOT NULL AND t.date_due < '" . $this->db->idate(dol_get_first_hour(dol_now())) . "'";
            } elseif (isset(self::STATES[$state])) {
                $sql .= ' AND t.status = ' . ((int) self::STATES[$state]);
            } else {
                throw new RestException(400, 'Bad state ' . $state . ': pending, done, invoiced or late');
            }
        }
        if ($thirdparty > 0) {
            $sql .= ' AND t.fk_soc = ' . ((int) $thirdparty);
        }
        // An external user sees the requests of his thirdparty only
        if (!empty(DolibarrApiAccess::$user->socid)) {
            $sql .= ' AND t.fk_soc = ' . ((int) DolibarrApiAccess::$user->socid);
        }
        if ($sqlfilters) {
            $errormessage = '';
            $sql         .= forgeSQLFromUniversalSearchCriteria($sqlfilters, $errormessage);
            if ($errormessage) {
                throw new RestException(400, 'Error when validating parameter sqlfilters -> ' . $errormessage);
            }
        }
        $sql .= $this->db->order($sortfield, $sortorder);
        if ($limit > 0) {
            $sql .= $this->db->plimit($limit, $limit * max(0, $page));
        }

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RestException(503, 'Error when retrieving the TMD requests: ' . $this->db->lasterror());
        }
        $out = [];
        while ($obj = $this->db->fetch_object($resql)) {
            $tmd = new Tmd($this->db);
            if ($tmd->fetch((int) $obj->rowid) > 0) {
                $out[] = $this->_filterObjectProperties($this->output($tmd), $properties);
            }
        }

        return $out;
    }

    /**
     * Create a TMD request
     *
     * The reference, the tracking number and the deadline (date of the mail plus the delay of the setup) are given by
     * DoliTMD. Fields: label (mandatory), ref_ext, date_mail, date_due, fk_soc, fk_socpeople, software_ref (nomeref
     * ID), email_from, fk_ticket. Dates as a timestamp or « YYYY-MM-DD HH:MM:SS ».
     *
     * Example: { "label": "DEMANDE ADR X1650956 ROUMANIE", "fk_soc": 12, "email_from": "client@example.com" }
     *
     * @param  array $request_data Request data
     * @phan-param ?array<string,mixed> $request_data
     * @phpstan-param ?array<string,mixed> $request_data
     * @return int ID of the request created
     *
     * @throws RestException 400 Bad request
     * @throws RestException 403 Forbidden
     * @throws RestException 500 Creation error
     */
    public function post($request_data = null): int
    {
        $this->checkRight('write');
        if (!is_array($request_data) || trim((string) ($request_data['label'] ?? '')) === '') {
            throw new RestException(400, 'label field missing');
        }
        if (isset($request_data['id']) || isset($request_data['ref'])) {
            throw new RestException(400, 'id and ref are given by DoliTMD');
        }

        $this->setFields($this->tmd, $request_data);
        $state = $this->stateOf($request_data);
        if ($state !== null) {
            $this->tmd->done     = $state >= Tmd::STATUS_DONE ? 1 : 0;
            $this->tmd->invoiced = $state == Tmd::STATUS_INVOICED ? 1 : 0;
        }
        if ($this->tmd->create(DolibarrApiAccess::$user) <= 0) {
            throw new RestException(500, 'Error creating the TMD request', array_merge([$this->tmd->error], (array) $this->tmd->errors));
        }

        return (int) $this->tmd->id;
    }

    /**
     * Change a TMD request
     *
     * Same fields as for the creation. The state changes with « state » (pending, done, invoiced) or the ticks « done »
     * and « invoiced » (invoiced means done): the ticket of the request is closed or opened again, and the mail
     * « request done » leaves if the setup asks for it, as when the box is ticked in the list.
     *
     * @param  int   $id           ID of the request
     * @param  array $request_data Fields to change
     * @phan-param ?array<string,mixed> $request_data
     * @phpstan-param ?array<string,mixed> $request_data
     * @return array|mixed Data of the request changed
     *
     * @throws RestException 400 Bad request
     * @throws RestException 403 Forbidden
     * @throws RestException 404 Not found
     * @throws RestException 500 Update error
     */
    public function put(int $id, $request_data = null)
    {
        $this->checkRight('write');
        $this->fetchTmd($id);

        $request_data = is_array($request_data) ? $request_data : [];
        $this->setFields($this->tmd, $request_data);
        $state = $this->stateOf($request_data, $this->tmd);
        if ($state !== null) {
            $this->tmd->done     = $state >= Tmd::STATUS_DONE ? 1 : 0;
            $this->tmd->invoiced = $state == Tmd::STATUS_INVOICED ? 1 : 0;
        }
        if ($this->quietly(function () {
            return $this->tmd->update(DolibarrApiAccess::$user);
        }) <= 0) {
            throw new RestException(500, 'Error updating the TMD request', array_merge([$this->tmd->error], (array) $this->tmd->errors));
        }

        return $this->get($id);
    }

    /**
     * Change the state of a TMD request: pending, done or invoiced
     *
     * As the state chosen in the list: the ticket of the request is closed (done) or opened again (pending), the mail
     * « request done » leaves if the setup asks for it.
     *
     * Example: { "state": "done" }
     *
     * @param  int    $id    ID of the request
     * @param  string $state pending, done or invoiced
     * @return array|mixed   Data of the request
     *
     * @url PUT {id}/state
     *
     * @throws RestException 400 Bad request
     * @throws RestException 403 Forbidden
     * @throws RestException 404 Not found
     * @throws RestException 500 Update error
     */
    public function putState(int $id, string $state)
    {
        $this->checkRight('write');
        $this->fetchTmd($id);
        $value = $this->stateOf(['state' => $state]);
        if ($this->quietly(function () use ($value) {
            return $this->tmd->setState(DolibarrApiAccess::$user, $value);
        }) <= 0) {
            throw new RestException(500, 'Error changing the state of the TMD request', array_merge([$this->tmd->error], (array) $this->tmd->errors));
        }

        return $this->get($id);
    }

    /**
     * Send the mail « request done », then the request is done
     *
     * As the button of the card: to the sender, in copy the people in To or in copy of the mail of the request and the
     * contacts of its ticket, the template « request done » filled, the documents of the request attached; in the
     * conversation of the mail of the request. Every field is optional: what is not given is taken from there.
     *
     * Example: { "cc": "a@example.com, b@example.com", "files": ["PB0099 IMDG.pdf"] }
     *
     * @param  int    $id      ID of the request
     * @param  string $to      Recipients, separated by commas (default: the sender)
     * @param  string $cc      Copies, separated by commas (default: the people in copy and the contacts of the ticket)
     * @param  string $subject Subject (default: from the template)
     * @param  string $message Message, HTML or text (default: from the template)
     * @param  array  $files   Names of documents of the request to attach (default: all of them, [] for none)
     * @return array           Recipients, documents attached, state of the request
     *
     * @url POST {id}/senddone
     *
     * @throws RestException 400 Bad request
     * @throws RestException 403 Forbidden
     * @throws RestException 404 Not found
     * @throws RestException 500 Mail not sent
     */
    public function postSendDone(int $id, string $to = '', string $cc = '', string $subject = '', string $message = '', $files = null): array
    {
        $this->checkRight('write');
        $this->fetchTmd($id);
        require_once __DIR__ . '/tmdmailer.class.php';

        $mailer = new TmdMailer($this->db);
        $draft  = $this->quietly(function () use ($mailer) {
            return $mailer->doneDraft($this->tmd);
        });
        $bad    = [];
        $toList = TmdMailer::parseAddresses($to !== '' ? $to : $draft['to'], $bad);
        $ccList = $cc !== '' ? TmdMailer::parseAddresses($cc, $bad) : $draft['cc'];
        if (!empty($bad)) {
            throw new RestException(400, 'Wrong address(es): ' . implode(', ', $bad));
        }
        if (empty($toList)) {
            throw new RestException(400, 'No recipient: the request has no sender, give to');
        }
        $html = $message !== '' ? $message : $draft['html'];
        if (!dol_textishtml($html)) {
            $html = dol_nl2br(dol_escape_htmltag($html));
        }
        $subject = $subject !== '' ? $subject : $draft['subject'];
        if (trim($subject) === '' || trim(dol_string_nohtmltag($html)) === '') {
            throw new RestException(400, 'Subject and message needed: the template « request done » is missing, give them');
        }
        $names = is_array($files) ? array_map('strval', $files) : array_column($draft['files'], 'name');
        $known = array_column($this->tmd->getPublicFiles(), 'name');
        if (array_diff($names, $known)) {
            throw new RestException(400, 'Unknown document(s): ' . implode(', ', array_diff($names, $known)));
        }

        $sent = $this->quietly(function () use ($mailer, $toList, $ccList, $subject, $html, $names) {
            return $mailer->sendDoneMail($this->tmd, DolibarrApiAccess::$user, $toList, $ccList, trim($subject), $html, $names);
        });
        if (!$sent) {
            throw new RestException(500, 'Mail not sent: ' . $mailer->error);
        }
        // Sent: the request is done, without the automatic mail of the tick
        if (empty($this->tmd->done)) {
            $this->tmd->context['dolitmd_done_mail_sent'] = 1;
            $this->quietly(function () {
                return $this->tmd->setFlag(DolibarrApiAccess::$user, 'done', 1);
            });
        }

        return [
            'to'          => array_column($toList, 'email'),
            'cc'          => array_column($ccList, 'email'),
            'attachments' => array_values($names),
            'state'       => $this->stateName((int) $this->tmd->status),
        ];
    }

    /**
     * Delete a TMD request
     *
     * @param  int   $id ID of the request
     * @return array
     * @phan-return array{success:array{code:int,message:string}}
     * @phpstan-return array{success:array{code:int,message:string}}
     *
     * @throws RestException 403 Forbidden
     * @throws RestException 404 Not found
     * @throws RestException 500 Deletion error
     */
    public function delete(int $id): array
    {
        $this->checkRight('delete');
        $this->fetchTmd($id);
        if ($this->tmd->delete(DolibarrApiAccess::$user) <= 0) {
            throw new RestException(500, 'Error deleting the TMD request: ' . $this->tmd->error);
        }

        return ['success' => ['code' => 200, 'message' => 'TMD request deleted']];
    }

    /**
     * The user has this right on the TMD requests
     *
     * @param  string $right read, write or delete
     * @return void
     *
     * @throws RestException 403 Forbidden
     */
    private function checkRight(string $right): void
    {
        if (!DolibarrApiAccess::$user->hasRight('dolitmd', 'tmd', $right)) {
            throw new RestException(403, 'No right to ' . $right . ' the TMD requests');
        }
    }

    /**
     * Load a request the user may see: never a deleted one
     *
     * @param  int  $id ID of the request
     * @return void
     *
     * @throws RestException 400 Bad request
     * @throws RestException 403 Forbidden
     * @throws RestException 404 Not found
     */
    private function fetchTmd(int $id): void
    {
        if ($id <= 0) {
            throw new RestException(400, 'Bad ID');
        }
        if ($this->tmd->fetch($id) <= 0 || (int) $this->tmd->status === Tmd::STATUS_DELETED) {
            throw new RestException(404, 'TMD request not found');
        }
        $this->checkAccess();
    }

    /**
     * The user may see the request loaded: its entity, its thirdparty for an external user
     *
     * @return void
     *
     * @throws RestException 403 Forbidden
     */
    private function checkAccess(): void
    {
        if (!DolibarrApi::_checkAccessToResource('tmd', $this->tmd->id, 'dolitmd_tmd')
            || (!empty(DolibarrApiAccess::$user->socid) && (int) $this->tmd->fk_soc !== (int) DolibarrApiAccess::$user->socid)) {
            throw new RestException(403, 'Access not allowed for login ' . DolibarrApiAccess::$user->login);
        }
    }

    /**
     * Fields of the API on a request: only the writable ones, dates read as timestamps or texts, links checked
     *
     * @param  Tmd                  $tmd  Request
     * @param  array<string,mixed>  $data Data received
     * @return void
     *
     * @throws RestException 400 Bad request
     */
    private function setFields(Tmd $tmd, array $data): void
    {
        foreach ($data as $field => $value) {
            if ($field === 'caller') {
                $tmd->context['caller'] = sanitizeVal($value, 'aZ09');
                continue;
            }
            if (!in_array($field, self::WRITABLE_FIELDS, true)) {
                continue;
            }
            if (in_array($field, ['date_mail', 'date_due'], true)) {
                $value = ($value === null || $value === '') ? null : (is_numeric($value) ? (int) $value : dol_stringtotime((string) $value));
                if ($value === false || ($value !== null && $value <= 0)) {
                    throw new RestException(400, 'Bad date for ' . $field);
                }
                $tmd->$field = $value;
                continue;
            }
            if ($field === 'email_from' && $value !== null && $value !== '' && !isValidEmail((string) $value)) {
                throw new RestException(400, 'Bad address for email_from');
            }
            $tmd->$field = $this->_checkValForAPI($field, $value, $tmd);
        }

        foreach (['fk_soc' => 'societe', 'fk_socpeople' => 'socpeople', 'fk_ticket' => 'ticket'] as $field => $table) {
            if (!empty($tmd->$field) && !is_object($this->db->getRow('SELECT rowid FROM ' . $this->db->prefix() . $table . ' WHERE rowid = ' . ((int) $tmd->$field)))) {
                throw new RestException(400, 'No ' . $table . ' with ID ' . ((int) $tmd->$field) . ' for ' . $field);
            }
        }
        // An external user only writes requests of his thirdparty
        if (!empty(DolibarrApiAccess::$user->socid)) {
            $tmd->fk_soc = (int) DolibarrApiAccess::$user->socid;
        }
    }

    /**
     * State asked by the data: « state » (pending, done, invoiced, or 0, 1, 2), else the ticks « done » and
     * « invoiced » (invoiced means done, not done means not invoiced)
     *
     * @param  array<string,mixed> $data    Data received
     * @param  Tmd|null            $current Request changed, null for a new one
     * @return int|null                     State, null when the data ask none
     *
     * @throws RestException 400 Bad request
     */
    private function stateOf(array $data, ?Tmd $current = null): ?int
    {
        $state = $data['state'] ?? ($data['status'] ?? null);
        if ($state !== null && $state !== '') {
            if (is_numeric($state) && in_array((int) $state, self::STATES, true)) {
                return (int) $state;
            }
            if (isset(self::STATES[(string) $state])) {
                return self::STATES[(string) $state];
            }
            throw new RestException(400, 'Bad state ' . $state . ': pending, done or invoiced');
        }
        if (!array_key_exists('done', $data) && !array_key_exists('invoiced', $data)) {
            return null;
        }
        $done     = array_key_exists('done', $data) ? (int) !empty($data['done']) : (int) !empty($current->done);
        $invoiced = array_key_exists('invoiced', $data) ? (int) !empty($data['invoiced']) : (int) !empty($current->invoiced);
        if (array_key_exists('invoiced', $data) && $invoiced) {
            $done = 1;
        }
        if (array_key_exists('done', $data) && !$done) {
            $invoiced = 0;
        }

        return $invoiced ? Tmd::STATUS_INVOICED : ($done ? Tmd::STATUS_DONE : Tmd::STATUS_PENDING);
    }

    /**
     * Name of a state
     *
     * @param  int    $status Status of the request
     * @return string         pending, done or invoiced
     */
    private function stateName(int $status): string
    {
        return (string) (array_search($status, self::STATES, true) ?: 'pending');
    }

    /**
     * Data of a request for the API: its fields, its state by name, late, the address of its tracking page
     *
     * @param  Tmd   $tmd Request
     * @return object
     */
    private function output(Tmd $tmd)
    {
        $late      = $tmd->isLate();
        $publicUrl = '';
        if (!empty($tmd->track_id)) {
            $publicUrl = dol_buildpath('/dolitmd/public/tmd/tracking.php', 2) . '?track_id=' . urlencode((string) $tmd->track_id) . '&entity=' . ((int) ($tmd->entity ?: 1));
        }
        $data             = $this->_cleanObjectDatas(clone $tmd);
        $data->state      = $this->stateName((int) $tmd->status);
        $data->late       = $late;
        $data->public_url = $publicUrl;

        return $data;
    }

    /**
     * Run without letting anything be printed: the core 24 prints warnings when a ticket is closed
     * (Ticket::listeContact()), which would break the JSON answer
     *
     * @param  callable $callback Work
     * @return mixed              Its result
     */
    private function quietly(callable $callback)
    {
        ob_start();
        try {
            return $callback();
        } finally {
            ob_end_clean();
        }
    }

    // phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
    /**
     * Clean the data of a request before giving it
     *
     * @param  object $object Request
     * @return object
     */
    protected function _cleanObjectDatas($object)
    {
        // phpcs:enable
        $object = parent::_cleanObjectDatas($object);

        unset($object->lines, $object->note, $object->address, $object->barcode_type, $object->barcode_type_code, $object->barcode_type_label, $object->barcode_type_coder);

        return $object;
    }
}
