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
 * \file    class/tmdstats.class.php
 * \ingroup dolitmd
 * \brief   Statistics of the TMD requests, read by view/tmd/tmd_stats.php (core/tpl/dolitmd_tmd_stats.tpl.php)
 */

require_once __DIR__ . '/tmd.class.php';

/**
 * Class for TmdStats
 *
 * Two families of figures, each label says which one:
 * - over the period chosen (received, done, invoiced): what happened in it, read on the date of the mail, of « done »,
 *   of the invoice, compared with the period before as far into it as the period chosen has gone;
 * - today (late, to invoice): the requests as they are now, whatever the period.
 * Never a deleted request (a merged one is deleted); an erroneous request (a mail that is no request) is only counted
 * as such.
 */
class TmdStats
{
    /**
     * @var string[] Periods offered, the month by default
     */
    public const PERIODS = ['month', 'quarter', 'year'];

    /**
     * @var int Months of the chart
     */
    public const NB_MONTHS = 12;

    /**
     * @var int[] Limits, in days, of the buckets of the processing time
     */
    public const DELAY_LIMITS = [1, 3, 7, 15];

    /**
     * @var int Lines of the short lists (late requests, customers, last requests done)
     */
    public const LIST_LIMIT = 8;

    /**
     * @var DoliDB Database handler
     */
    public DoliDB $db;

    /**
     * @var string Period chosen, one of PERIODS
     */
    protected string $period = 'month';

    /**
     * @var int Bounds of the period chosen and of the one it is compared with
     */
    protected int $periodStart = 0;
    protected int $periodEnd = 0;
    protected int $comparisonStart = 0;
    protected int $comparisonEnd = 0;

    /**
     * @var int Timestamp the statistics are read at, the same for every figure
     */
    protected int $now = 0;

    /**
     * @var array<int,object> Requests alive (not deleted), keyed by id, dates as timestamps
     */
    protected array $requests = [];

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct(DoliDB $db)
    {
        $this->db  = $db;
        $this->now = dol_now();
    }

    /**
     * Statistics of the period, everything the page shows
     *
     * @param  string $period One of PERIODS, the month otherwise
     * @return array
     */
    public function load(string $period): array
    {
        $this->setPeriod($period);
        $this->loadRequests();

        return [
            'period'      => $this->period,
            'periodStart' => $this->periodStart,
            'periodEnd'   => $this->periodEnd,
            'kpis'        => $this->getKpis(),
            'chart'       => $this->getChart(),
            'states'      => $this->getStates(),
            'categories'  => $this->getCategories(),
            'delays'      => $this->getDelays(),
            'late'        => $this->getLate(),
            'customers'   => $this->getCustomers(),
            'lastDone'    => $this->getLastDone(),
        ];
    }

    /**
     * Bounds of the period (month, quarter, year in progress) and of the one before, as far into it as this one has gone
     *
     * @param  string $period One of PERIODS
     * @return void
     */
    protected function setPeriod(string $period): void
    {
        $this->period = in_array($period, self::PERIODS, true) ? $period : 'month';

        $year  = (int) dol_print_date($this->now, '%Y');
        $month = (int) dol_print_date($this->now, '%m');
        switch ($this->period) {
            case 'quarter':
                $start = dol_get_first_day($year, intdiv($month - 1, 3) * 3 + 1);
                $span  = 3;
                break;
            case 'year':
                $start = dol_get_first_day($year, 1);
                $span  = 12;
                break;
            default:
                $start = dol_get_first_day($year, $month);
                $span  = 1;
                break;
        }
        $this->periodStart     = (int) $start;
        $this->periodEnd       = $this->now;
        $this->comparisonStart = (int) dol_time_plus_duree($this->periodStart, -$span, 'm');
        $this->comparisonEnd   = (int) dol_time_plus_duree($this->periodEnd, -$span, 'm');
    }

    /**
     * The requests alive of the entities of the user, read once: a few thousand a year, every figure is counted in PHP
     *
     * @return void
     */
    protected function loadRequests(): void
    {
        $sql = 'SELECT t.rowid, t.ref, t.label, t.category, t.status, t.done, t.invoiced, t.fk_soc, s.nom AS soc_name,'
            . ' t.fk_user_done, t.date_mail, t.date_creation, t.date_due, t.date_done, t.date_invoiced'
            . ' FROM ' . $this->db->prefix() . 'dolitmd_tmd AS t'
            . ' LEFT JOIN ' . $this->db->prefix() . 'societe AS s ON s.rowid = t.fk_soc'
            . ' WHERE t.entity IN (' . getEntity('tmd') . ') AND t.status >= 0'
            . ' ORDER BY t.rowid';
        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog(__METHOD__ . ' ' . $this->db->lasterror(), LOG_ERR);
            return;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            // Received: the date of the mail, else of the creation (request typed by hand)
            $obj->received      = (int) ($this->db->jdate($obj->date_mail) ?: $this->db->jdate($obj->date_creation));
            $obj->date_due      = (int) $this->db->jdate($obj->date_due);
            $obj->date_done     = (int) $this->db->jdate($obj->date_done);
            $obj->date_invoiced = (int) $this->db->jdate($obj->date_invoiced);
            $obj->status        = (int) $obj->status;
            $obj->category      = Tmd::normalizeCategory($obj->category) ?? '';
            $this->requests[(int) $obj->rowid] = $obj;
        }
        $this->db->free($resql);
    }

    /**
     * The date is between the bounds
     *
     * @param  int  $date  Timestamp, 0 when there is none
     * @param  int  $start First second
     * @param  int  $end   Last second
     * @return bool
     */
    protected function between(int $date, int $start, int $end): bool
    {
        return $date > 0 && $date >= $start && $date <= $end;
    }

    /**
     * The date is in the period chosen
     *
     * @param  int  $date Timestamp, 0 when there is none
     * @return bool
     */
    protected function inPeriod(int $date): bool
    {
        return $this->between($date, $this->periodStart, $this->periodEnd);
    }

    /**
     * A real request: not erroneous
     *
     * @param  object $request Row of loadRequests()
     * @return bool
     */
    protected function isRequest(object $request): bool
    {
        return $request->status !== Tmd::STATUS_ERRONEOUS;
    }

    /**
     * Late today, as Tmd::isLate(): not done, nor subcontracted, nor erroneous, deadline before today
     *
     * @param  object $request Row of loadRequests()
     * @return bool
     */
    protected function isLate(object $request): bool
    {
        return empty($request->done) && $request->date_due > 0 && !in_array($request->status, Tmd::STATES_ASIDE, true)
            && $request->date_due < dol_get_first_hour($this->now);
    }

    /**
     * Processing time of a request done: from its mail to « done », 0 when it cannot be read
     *
     * @param  object $request Row of loadRequests()
     * @return int             Seconds
     */
    protected function processingTime(object $request): int
    {
        return !empty($request->done) && $request->received > 0 && $request->date_done > $request->received ? $request->date_done - $request->received : 0;
    }

    /**
     * A duration in days, one decimal: « 2,4 j », « < 0,1 j » for a few minutes
     *
     * @param  float $seconds Duration
     * @return string
     */
    public static function days(float $seconds): string
    {
        global $langs;

        if ($seconds > 0 && round($seconds / 86400, 1) == 0) {
            return '< ' . $langs->transnoentities('TmdStatsDays', price(0.1, 0, $langs, 0, 0));
        }

        // Rounding 0: the decimal is shown when there is one (« 2,4 j », « 3 j »)
        return $langs->transnoentities('TmdStatsDays', price(round($seconds / 86400, 1), 0, $langs, 0, 0));
    }

    /**
     * Change of a figure against the period before, as a coloured badge
     *
     * @param  int    $value    Figure of the period
     * @param  int    $previous Figure of the period before
     * @param  string $title    Tooltip of the badge
     * @return string           HTML, empty when there is nothing to compare with
     */
    protected function evolution(int $value, int $previous, string $title): string
    {
        if ($previous === 0) {
            return '';
        }
        $change = (int) round(($value - $previous) / $previous * 100);
        $state  = $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat');

        return '<span class="dolitmd-stats-evolution dolitmd-stats-evolution--' . $state . '" title="' . dol_escape_htmltag($title) . '">' . ($change > 0 ? '+' : '') . $change . '&nbsp;%</span>';
    }

    /**
     * URL of the list of the requests, with more criteria
     *
     * @param  string $query Criteria, each one starting with &
     * @return string
     */
    protected function listUrl(string $query = ''): string
    {
        return dol_buildpath('/saturne/view/saturne_list.php', 1) . '?object_type=tmd&mainmenu=dolitmd&leftmenu=tmd' . $query;
    }

    /**
     * Criteria of the late requests, as the quick filter « Late » of the list: not done, pending, deadline yesterday at
     * the latest
     *
     * @return string
     */
    protected function lateQuery(): string
    {
        $yesterday = dol_time_plus_duree($this->now, -1, 'd');

        return '&search_done=0&search_status[]=' . Tmd::STATUS_PENDING . '&search_date_due_dtendday=' . dol_print_date($yesterday, '%d')
            . '&search_date_due_dtendmonth=' . dol_print_date($yesterday, '%m') . '&search_date_due_dtendyear=' . dol_print_date($yesterday, '%Y');
    }

    /**
     * The four key figures: received, done, in time, to invoice
     *
     * @return array<int,array{tone:string,icon:string,title:string,value:string,evolution:string,detail:string,alert:string,url:string}>
     */
    protected function getKpis(): array
    {
        global $langs;

        $count = ['received' => 0, 'previousReceived' => 0, 'subcontracted' => 0, 'erroneous' => 0, 'done' => 0, 'previousDone' => 0, 'invoiced' => 0, 'toInvoice' => 0, 'late' => 0, 'withDue' => 0, 'inTime' => 0];
        $times = [];
        foreach ($this->requests as $request) {
            $count['late'] += $this->isLate($request) ? 1 : 0;
            if (!$this->isRequest($request)) {
                $count['erroneous'] += $this->inPeriod($request->received) ? 1 : 0;
                continue;
            }
            if ($this->inPeriod($request->received)) {
                $count['received']++;
                $count['subcontracted'] += $request->status === Tmd::STATUS_SUBCONTRACTED ? 1 : 0;
            }
            $count['previousReceived'] += $this->between($request->received, $this->comparisonStart, $this->comparisonEnd) ? 1 : 0;
            $count['toInvoice']        += $request->status === Tmd::STATUS_DONE ? 1 : 0;
            $count['invoiced']         += !empty($request->invoiced) && $this->inPeriod($request->date_invoiced) ? 1 : 0;
            if (empty($request->done)) {
                continue;
            }
            $count['previousDone'] += $this->between($request->date_done, $this->comparisonStart, $this->comparisonEnd) ? 1 : 0;
            if (!$this->inPeriod($request->date_done)) {
                continue;
            }
            $count['done']++;
            $time = $this->processingTime($request);
            if ($time > 0) {
                $times[] = $time;
            }
            // In time: done on its deadline at the latest (a date, the whole day counts)
            if ($request->date_due > 0) {
                $count['withDue']++;
                $count['inTime'] += $request->date_done < $request->date_due + 86400 ? 1 : 0;
            }
        }

        sort($times);
        $nbTimes = count($times);
        $median  = $nbTimes > 0 ? ($nbTimes % 2 ? $times[intdiv($nbTimes, 2)] : ($times[$nbTimes / 2 - 1] + $times[$nbTimes / 2]) / 2) : 0;
        $compare = $langs->transnoentities('TmdStatsComparedWith', dol_print_date($this->comparisonStart, 'day'), dol_print_date($this->comparisonEnd, 'day'));

        $receivedDetail = [$langs->transnoentities('TmdStatsSubcontractedNb', $count['subcontracted'])];
        if ($count['erroneous'] > 0) {
            $receivedDetail[] = $langs->transnoentities('TmdStatsErroneousNb', $count['erroneous']);
        }

        return [
            [
                'tone'      => 'received',
                'icon'      => 'fas fa-inbox',
                'title'     => $langs->transnoentities('TmdStatsReceived'),
                'value'     => (string) $count['received'],
                'evolution' => $this->evolution($count['received'], $count['previousReceived'], $compare),
                'detail'    => implode(' · ', $receivedDetail),
                'alert'     => '',
                'url'       => $this->listUrl(),
            ],
            [
                'tone'      => 'done',
                'icon'      => 'fas fa-check',
                'title'     => $langs->transnoentities('TmdStatsDone'),
                'value'     => (string) $count['done'],
                'evolution' => $this->evolution($count['done'], $count['previousDone'], $compare),
                'detail'    => $nbTimes > 0 ? $langs->transnoentities('TmdStatsTimes', self::days(array_sum($times) / $nbTimes), self::days($median)) : $langs->transnoentities('TmdStatsNoTime'),
                'alert'     => '',
                'url'       => $this->listUrl('&search_status[]=' . Tmd::STATUS_DONE . '&search_status[]=' . Tmd::STATUS_INVOICED),
            ],
            [
                'tone'      => 'delay',
                'icon'      => 'fas fa-stopwatch',
                'title'     => $langs->transnoentities('TmdStatsInTime'),
                'value'     => $count['withDue'] > 0 ? round($count['inTime'] * 100 / $count['withDue']) . '&nbsp;%' : '-',
                'evolution' => '',
                'detail'    => $langs->transnoentities('TmdStatsInTimeDetail', $count['inTime'], $count['withDue']),
                'alert'     => $count['late'] > 0 ? $langs->transnoentities('TmdStatsLateNb', $count['late']) : '',
                'url'       => $count['late'] > 0 ? $this->listUrl($this->lateQuery()) : $this->listUrl(),
            ],
            [
                'tone'      => 'invoice',
                'icon'      => 'fas fa-file-invoice-dollar',
                'title'     => $langs->transnoentities('TmdStatsToInvoice'),
                'value'     => (string) $count['toInvoice'],
                'evolution' => '',
                'detail'    => $langs->transnoentities('TmdStatsInvoicedNb', $count['invoiced']),
                'alert'     => '',
                'url'       => $this->listUrl('&search_status[]=' . Tmd::STATUS_DONE),
            ],
        ];
    }

    /**
     * Requests received each of the last twelve months, next to the same month one year earlier (whatever the period)
     *
     * @return array{months:array,scale:int,ticks:int[],total:int,evolution:string}
     */
    protected function getChart(): array
    {
        global $langs;

        $first  = dol_get_first_day((int) dol_print_date($this->now, '%Y'), (int) dol_print_date($this->now, '%m'));
        $counts = [];
        foreach ($this->requests as $request) {
            if ($this->isRequest($request) && $request->received > 0) {
                $month          = dol_print_date($request->received, '%Y-%m');
                $counts[$month] = ($counts[$month] ?? 0) + 1;
            }
        }

        $months = [];
        $max    = 0;
        for ($i = self::NB_MONTHS - 1; $i >= 0; $i--) {
            $start    = dol_time_plus_duree($first, -$i, 'm');
            $before   = dol_time_plus_duree($start, -12, 'm');
            $current  = $counts[dol_print_date($start, '%Y-%m')] ?? 0;
            $previous = $counts[dol_print_date($before, '%Y-%m')] ?? 0;
            $months[] = [
                'label'         => dol_print_date($start, '%b'),
                'name'          => dol_print_date($start, '%B'),
                'title'         => dol_print_date($start, '%B %Y'),
                'previousTitle' => dol_print_date($before, '%B %Y'),
                'current'       => $current,
                'previous'      => $previous,
                'evolution'     => $previous > 0 ? ($current >= $previous ? '+' : '') . round(($current - $previous) / $previous * 100) . ' %' : '',
            ];
            $max = max($max, $current, $previous);
        }

        // Four steps of 1, 2 or 5 times a power of ten: whole numbers of requests on the axis
        $raw  = max(1, $max / 4);
        $unit = pow(10, floor(log10($raw)));
        $step = $unit;
        foreach ([1, 2, 5, 10] as $factor) {
            if ($raw <= $factor * $unit) {
                $step = $factor * $unit;
                break;
            }
        }
        $step  = max(1, (int) $step);
        $total = (int) array_sum(array_column($months, 'current'));

        return [
            'months'    => $months,
            'scale'     => $step * 4,
            'ticks'     => [$step * 4, $step * 3, $step * 2, $step, 0],
            'total'     => $total,
            'evolution' => $this->evolution($total, (int) array_sum(array_column($months, 'previous')), $langs->transnoentities('TmdStatsChartCompared')),
        ];
    }

    /**
     * State today of the requests received over the period, a bar each
     *
     * @return array{rows:array,max:int,late:int,lateUrl:string}
     */
    protected function getStates(): array
    {
        global $langs;

        $states = [
            Tmd::STATUS_PENDING       => ['TmdStatusPending', 'pending'],
            Tmd::STATUS_SUBCONTRACTED => ['TmdStatusSubcontracted', 'subcontracted'],
            Tmd::STATUS_DONE          => ['TmdStatusDoneToInvoice', 'done'],
            Tmd::STATUS_INVOICED      => ['TmdStatusInvoiced', 'invoiced'],
        ];
        $counts = array_fill_keys(array_keys($states), 0);
        $late   = 0;
        foreach ($this->requests as $request) {
            $late += $this->isLate($request) ? 1 : 0;
            if (isset($counts[$request->status]) && $this->inPeriod($request->received)) {
                $counts[$request->status]++;
            }
        }

        $rows = [];
        foreach ($states as $status => $state) {
            $rows[] = ['label' => $langs->transnoentities($state[0]), 'tone' => $state[1], 'nb' => $counts[$status], 'url' => $this->listUrl('&search_status[]=' . $status)];
        }

        return ['rows' => $this->withWidths($rows), 'max' => max($counts), 'late' => $late, 'lateUrl' => $this->listUrl($this->lateQuery())];
    }

    /**
     * Width of the bar of each row of a card, in % of the largest figure of the card
     *
     * @param  array<int,array{nb:int}> $rows Rows of the card
     * @return array                          Rows with their width
     */
    protected function withWidths(array $rows): array
    {
        $max = max(array_column($rows, 'nb') ?: [0]);
        foreach ($rows as $key => $row) {
            $rows[$key]['width'] = $max > 0 ? round($row['nb'] / $max * 100, 1) : 0;
        }

        return $rows;
    }

    /**
     * Category of the requests received over the period, a bar each
     *
     * @return array{rows:array,max:int}
     */
    protected function getCategories(): array
    {
        global $langs;

        $counts = ['ADR' => 0, 'IATA' => 0, 'IMDG' => 0, '' => 0];
        foreach ($this->requests as $request) {
            if ($this->isRequest($request) && $this->inPeriod($request->received)) {
                $counts[$request->category]++;
            }
        }

        $rows = [];
        foreach ($counts as $category => $nb) {
            $rows[] = [
                'label' => $category !== '' ? $category : $langs->transnoentities('TmdStatsNoCategory'),
                'icon'  => Tmd::CATEGORY_PICTOS[$category] ?? 'fa-question',
                'tone'  => $category !== '' ? strtolower($category) : 'none',
                'nb'    => $nb,
                'url'   => $category !== '' ? $this->listUrl('&search_category=' . $category) : '',
            ];
        }

        return ['rows' => $this->withWidths($rows), 'max' => max($counts)];
    }

    /**
     * Processing time of the requests done over the period, by buckets of days
     *
     * @return array{rows:array,max:int,nb:int}
     */
    protected function getDelays(): array
    {
        global $langs;

        $rows     = [];
        $previous = 0;
        foreach (self::DELAY_LIMITS as $limit) {
            $rows[]   = ['label' => $previous === 0 ? $langs->transnoentities('TmdStatsUnderDays', $limit) : $langs->transnoentities('TmdStatsBetweenDays', $previous, $limit), 'from' => $previous * 86400, 'to' => $limit * 86400, 'nb' => 0];
            $previous = $limit;
        }
        $rows[] = ['label' => $langs->transnoentities('TmdStatsOverDays', $previous), 'from' => $previous * 86400, 'to' => 0, 'nb' => 0];

        foreach ($this->requests as $request) {
            $time = $this->isRequest($request) && $this->inPeriod($request->date_done) ? $this->processingTime($request) : 0;
            if ($time <= 0) {
                continue;
            }
            foreach ($rows as $key => $row) {
                if ($time >= $row['from'] && (empty($row['to']) || $time < $row['to'])) {
                    $rows[$key]['nb']++;
                    break;
                }
            }
        }
        $counts = array_column($rows, 'nb');

        return ['rows' => $this->withWidths($rows), 'max' => max($counts), 'nb' => array_sum($counts)];
    }

    /**
     * Requests late today, the most late first
     *
     * @return array{rows:array,nb:int,url:string}
     */
    protected function getLate(): array
    {
        $late = array_filter($this->requests, [$this, 'isLate']);
        uasort($late, static function ($a, $b) {
            return $a->date_due <=> $b->date_due;
        });

        $tmd   = new Tmd($this->db);
        $today = dol_get_first_hour($this->now);
        $rows  = [];
        foreach (array_slice($late, 0, self::LIST_LIMIT, true) as $id => $request) {
            $tmd->id     = $id;
            $tmd->ref    = $request->ref;
            $tmd->label  = $request->label;
            $tmd->status = $request->status;
            $rows[]      = [
                'link'       => $tmd->getNomUrl(1),
                'subject'    => (string) $request->label,
                'thirdparty' => $this->thirdparty($request),
                'due'        => $request->date_due,
                'days'       => (int) floor(($today - $request->date_due) / 86400),
            ];
        }

        return ['rows' => $rows, 'nb' => count($late), 'url' => $this->listUrl($this->lateQuery())];
    }

    /**
     * Customers with the most requests received over the period: received, done, mean processing time, to invoice
     *
     * @return array<int,array{thirdparty:string,received:int,done:int,time:string,toInvoice:int}>
     */
    protected function getCustomers(): array
    {
        global $langs;

        $perSoc = [];
        foreach ($this->requests as $request) {
            if (!$this->isRequest($request) || !$this->inPeriod($request->received)) {
                continue;
            }
            $socId = (int) $request->fk_soc;
            if (!isset($perSoc[$socId])) {
                $perSoc[$socId] = ['request' => $request, 'received' => 0, 'done' => 0, 'times' => [], 'toInvoice' => 0];
            }
            $perSoc[$socId]['received']++;
            $perSoc[$socId]['done']      += !empty($request->done) ? 1 : 0;
            $perSoc[$socId]['toInvoice'] += $request->status === Tmd::STATUS_DONE ? 1 : 0;
            $time = $this->processingTime($request);
            if ($time > 0) {
                $perSoc[$socId]['times'][] = $time;
            }
        }
        uasort($perSoc, static function ($a, $b) {
            return $b['received'] <=> $a['received'];
        });

        $rows = [];
        foreach (array_slice($perSoc, 0, self::LIST_LIMIT, true) as $socId => $line) {
            $rows[] = [
                'thirdparty' => $socId > 0 ? $this->thirdparty($line['request']) : '<span class="opacitymedium">' . $langs->trans('TmdStatsNoThirdparty') . '</span>',
                'received'   => $line['received'],
                'done'       => $line['done'],
                'time'       => !empty($line['times']) ? self::days(array_sum($line['times']) / count($line['times'])) : '-',
                'toInvoice'  => $line['toInvoice'],
            ];
        }

        return $rows;
    }

    /**
     * The last requests done, whatever the period: who did it and when
     *
     * @return array<int,array{icon:string,link:string,subject:string,thirdparty:string,date:int,user:?User}>
     */
    protected function getLastDone(): array
    {
        $done = array_filter($this->requests, static function ($request) {
            return !empty($request->done) && $request->date_done > 0 && $request->status !== Tmd::STATUS_ERRONEOUS;
        });
        uasort($done, static function ($a, $b) {
            return $b->date_done <=> $a->date_done;
        });

        $tmd   = new Tmd($this->db);
        $users = [];
        $rows  = [];
        foreach (array_slice($done, 0, self::LIST_LIMIT, true) as $id => $request) {
            $userId = (int) $request->fk_user_done;
            if ($userId > 0 && !array_key_exists($userId, $users)) {
                $user           = new User($this->db);
                $users[$userId] = $user->fetch($userId) > 0 ? $user : null;
            }
            $tmd->id     = $id;
            $tmd->ref    = $request->ref;
            $tmd->label  = $request->label;
            $tmd->status = $request->status;
            $rows[]      = [
                'icon'       => Tmd::CATEGORY_PICTOS[$request->category] ?? 'fa-truck-moving',
                'link'       => $tmd->getNomUrl(1),
                'subject'    => (string) $request->label,
                'thirdparty' => $this->thirdparty($request),
                'date'       => $request->date_done,
                'user'       => $userId > 0 ? $users[$userId] : null,
            ];
        }

        return $rows;
    }

    /**
     * Link to the thirdparty of a request, empty without one
     *
     * @param  object $request Row of loadRequests()
     * @return string
     */
    protected function thirdparty(object $request): string
    {
        if ((int) $request->fk_soc <= 0) {
            return '';
        }
        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
        $societe       = new Societe($this->db);
        $societe->id   = (int) $request->fk_soc;
        $societe->name = (string) $request->soc_name;

        return $societe->getNomUrl(1);
    }
}
