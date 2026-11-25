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
 * \file    core/tpl/dolitmd_tmd_stats.tpl.php
 * \ingroup dolitmd
 * \brief   Statistics of the TMD requests, in the design of the home page of ReedCRM: period, key figures, requests
 *          received over twelve months, states, categories, processing times, late requests, customers, last done
 */

/**
 * The following variables must be defined by the caller:
 *
 * @var Translate $langs
 * @var array     $stats Statistics, from TmdStats::load()
 */

// The chart draws in a fixed box stretched over the plot: a month is 100 units wide, the plot 300 units high
$chartHeight = 300;

$periods = ['month' => 'TmdStatsPeriodMonth', 'quarter' => 'TmdStatsPeriodQuarter', 'year' => 'TmdStatsPeriodYear'];
?>
<div class="dolitmd-stats">
    <div class="dolitmd-stats__toolbar">
        <div class="dolitmd-stats__range">
            <i class="far fa-calendar-alt"></i>
            <?php echo $langs->trans('TmdStatsRange', dol_print_date($stats['periodStart'], 'day', 'tzuserrel'), dol_print_date($stats['periodEnd'], 'day', 'tzuserrel')); ?>
        </div>
        <nav class="dolitmd-stats-segmented" aria-label="<?php echo dol_escape_htmltag($langs->trans('TmdStatsPeriodFilter')); ?>">
            <?php foreach ($periods as $periodKey => $periodLabel) : ?>
                <a class="dolitmd-stats-segmented__item<?php echo $stats['period'] === $periodKey ? ' is-active' : ''; ?>" href="<?php echo dol_escape_htmltag($_SERVER['PHP_SELF'] . '?' . http_build_query(['mainmenu' => 'dolitmd', 'leftmenu' => 'tmd_stats', 'period' => $periodKey])); ?>"><?php echo $langs->trans($periodLabel); ?></a>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="dolitmd-stats__kpis">
        <?php foreach ($stats['kpis'] as $kpi) : ?>
            <a class="dolitmd-stats-kpi dolitmd-stats-kpi--<?php echo $kpi['tone']; ?>" href="<?php echo dol_escape_htmltag($kpi['url']); ?>">
                <span class="dolitmd-stats-kpi__head">
                    <span class="dolitmd-stats-kpi__icon"><i class="<?php echo $kpi['icon']; ?>"></i></span>
                    <span class="dolitmd-stats-kpi__title"><?php echo dol_escape_htmltag($kpi['title']); ?></span>
                </span>
                <span class="dolitmd-stats-kpi__value"><?php echo $kpi['value']; ?></span>
                <?php if (!empty($kpi['evolution'])) : ?>
                    <span class="dolitmd-stats-kpi__trend"><?php echo $kpi['evolution']; ?><span class="dolitmd-stats-kpi__compare"><?php echo $langs->trans('TmdStatsComparedWith' . ucfirst($stats['period'])); ?></span></span>
                <?php endif; ?>
                <span class="dolitmd-stats-kpi__detail"><?php echo dol_escape_htmltag($kpi['detail']); ?></span>
                <?php if (!empty($kpi['alert'])) : ?>
                    <span class="dolitmd-stats-kpi__alert"><i class="fas fa-exclamation-triangle"></i><?php echo dol_escape_htmltag($kpi['alert']); ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="dolitmd-stats__grid">
        <?php $chart = $stats['chart']; ?>
        <section class="dolitmd-stats-card dolitmd-stats-card--wide">
            <header class="dolitmd-stats-card__header">
                <h3 class="dolitmd-stats-card__title"><i class="fas fa-chart-bar"></i><?php echo $langs->trans('TmdStatsChart', count($chart['months'])); ?></h3>
                <span class="dolitmd-stats-card__figure"><?php echo $chart['total'] . ' ' . $chart['evolution']; ?></span>
            </header>
            <div class="dolitmd-stats-legend">
                <span class="dolitmd-stats-legend__item dolitmd-stats-legend__item--current"><?php echo $langs->trans('TmdStatsChartCurrent'); ?></span>
                <span class="dolitmd-stats-legend__item dolitmd-stats-legend__item--previous"><?php echo $langs->trans('TmdStatsChartPrevious'); ?></span>
            </div>
            <div class="dolitmd-stats-chart">
                <div class="dolitmd-stats-chart__ticks">
                    <?php foreach ($chart['ticks'] as $tick) : ?>
                        <span class="dolitmd-stats-chart__tick"><?php echo $tick; ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="dolitmd-stats-chart__plot">
                    <svg class="dolitmd-stats-chart__bars" viewBox="0 0 <?php echo count($chart['months']) * 100; ?> <?php echo $chartHeight; ?>" preserveAspectRatio="none" role="img" aria-label="<?php echo dol_escape_htmltag($langs->trans('TmdStatsChart', count($chart['months']))); ?>">
                        <?php foreach ($chart['months'] as $index => $month) :
                            $previousHeight = $chart['scale'] > 0 ? round($month['previous'] / $chart['scale'] * $chartHeight, 1) : 0;
                            $currentHeight  = $chart['scale'] > 0 ? round($month['current'] / $chart['scale'] * $chartHeight, 1) : 0; ?>
                            <rect class="dolitmd-stats-chart__bar dolitmd-stats-chart__bar--previous" x="<?php echo $index * 100 + 16; ?>" y="<?php echo $chartHeight - $previousHeight; ?>" width="32" height="<?php echo $previousHeight; ?>"></rect>
                            <rect class="dolitmd-stats-chart__bar dolitmd-stats-chart__bar--current" x="<?php echo $index * 100 + 52; ?>" y="<?php echo $chartHeight - $currentHeight; ?>" width="32" height="<?php echo $currentHeight; ?>"></rect>
                            <rect class="dolitmd-stats-chart__hit" x="<?php echo $index * 100; ?>" y="0" width="100" height="<?php echo $chartHeight; ?>"
                                  data-index="<?php echo $index; ?>"
                                  data-month="<?php echo dol_escape_htmltag(ucfirst($month['name'])); ?>"
                                  data-title="<?php echo dol_escape_htmltag(ucfirst($month['title'])); ?>"
                                  data-current="<?php echo $month['current']; ?>"
                                  data-previous-title="<?php echo dol_escape_htmltag(ucfirst($month['previousTitle'])); ?>"
                                  data-previous="<?php echo $month['previous']; ?>"
                                  data-evolution="<?php echo dol_escape_htmltag($month['evolution']); ?>"></rect>
                        <?php endforeach; ?>
                    </svg>
                    <div class="dolitmd-stats-chart__tooltip" hidden></div>
                </div>
                <div class="dolitmd-stats-chart__months">
                    <?php foreach ($chart['months'] as $month) : ?>
                        <span><?php echo dol_escape_htmltag($month['label']); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <details class="dolitmd-stats-chart__table">
                <summary><?php echo $langs->trans('TmdStatsChartDetail'); ?></summary>
                <div class="div-table-responsive-no-min">
                    <table class="noborder centpercent">
                        <tr class="liste_titre">
                            <td><?php echo $langs->trans('Month'); ?></td>
                            <td class="right"><?php echo $langs->trans('TmdStatsChartCurrent'); ?></td>
                            <td class="right"><?php echo $langs->trans('TmdStatsChartPrevious'); ?></td>
                            <td class="right"><?php echo $langs->trans('TmdStatsChange'); ?></td>
                        </tr>
                        <?php foreach ($chart['months'] as $month) : ?>
                            <tr class="oddeven">
                                <td><?php echo dol_escape_htmltag(ucfirst($month['title'])); ?></td>
                                <td class="right"><?php echo $month['current']; ?></td>
                                <td class="right"><?php echo $month['previous']; ?></td>
                                <td class="right nowraponall"><?php echo dol_escape_htmltag($month['evolution']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </details>
        </section>

        <?php $states = $stats['states']; ?>
        <section class="dolitmd-stats-card dolitmd-stats-card--narrow">
            <header class="dolitmd-stats-card__header">
                <h3 class="dolitmd-stats-card__title"><i class="fas fa-tasks"></i><?php echo $langs->trans('TmdStatsStates'); ?></h3>
                <a class="dolitmd-stats-card__more" href="<?php echo dol_escape_htmltag($stats['kpis'][0]['url']); ?>"><?php echo $langs->trans('TmdStatsSeeAll'); ?><i class="fas fa-arrow-right"></i></a>
            </header>
            <?php if ($states['max'] > 0) : ?>
                <ul class="dolitmd-stats-bars">
                    <?php foreach ($states['rows'] as $row) : ?>
                        <li>
                            <a class="dolitmd-stats-bars__row" href="<?php echo dol_escape_htmltag($row['url']); ?>">
                                <span class="dolitmd-stats-bars__label"><?php echo dol_escape_htmltag($row['label']); ?></span>
                                <span class="dolitmd-stats-bars__value"><?php echo $row['nb']; ?></span>
                                <svg class="dolitmd-stats-bars__bar" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true">
                                    <rect class="dolitmd-stats-bars__fill dolitmd-stats-bars__fill--<?php echo $row['tone']; ?>" width="<?php echo $row['width']; ?>" height="10"></rect>
                                </svg>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <div class="dolitmd-stats-empty"><i class="fas fa-inbox"></i><?php echo $langs->trans('TmdStatsNoRequest'); ?></div>
            <?php endif; ?>
            <?php if ($states['late'] > 0) : ?>
                <footer class="dolitmd-stats-card__footer dolitmd-stats-card__footer--alert">
                    <a href="<?php echo dol_escape_htmltag($states['lateUrl']); ?>"><i class="fas fa-exclamation-triangle"></i><?php echo $langs->trans('TmdStatsLateNb', $states['late']); ?></a>
                </footer>
            <?php else : ?>
                <footer class="dolitmd-stats-card__footer"><i class="fas fa-check"></i><?php echo $langs->trans('TmdStatsNoLate'); ?></footer>
            <?php endif; ?>
        </section>

        <?php $categories = $stats['categories']; ?>
        <section class="dolitmd-stats-card dolitmd-stats-card--third">
            <header class="dolitmd-stats-card__header">
                <h3 class="dolitmd-stats-card__title"><i class="fas fa-tags"></i><?php echo $langs->trans('TmdStatsCategories'); ?></h3>
            </header>
            <?php if ($categories['max'] > 0) : ?>
                <ul class="dolitmd-stats-bars">
                    <?php foreach ($categories['rows'] as $row) : ?>
                        <li>
                            <?php echo $row['url'] !== '' ? '<a class="dolitmd-stats-bars__row" href="' . dol_escape_htmltag($row['url']) . '">' : '<span class="dolitmd-stats-bars__row">'; ?>
                                <span class="dolitmd-stats-bars__label"><i class="fas <?php echo $row['icon']; ?>"></i><?php echo dol_escape_htmltag($row['label']); ?></span>
                                <span class="dolitmd-stats-bars__value"><?php echo $row['nb']; ?></span>
                                <svg class="dolitmd-stats-bars__bar" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true">
                                    <rect class="dolitmd-stats-bars__fill dolitmd-stats-bars__fill--<?php echo $row['tone']; ?>" width="<?php echo $row['width']; ?>" height="10"></rect>
                                </svg>
                            <?php echo $row['url'] !== '' ? '</a>' : '</span>'; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <div class="dolitmd-stats-empty"><i class="fas fa-tags"></i><?php echo $langs->trans('TmdStatsNoRequest'); ?></div>
            <?php endif; ?>
        </section>

        <?php $delays = $stats['delays']; ?>
        <section class="dolitmd-stats-card dolitmd-stats-card--third">
            <header class="dolitmd-stats-card__header">
                <h3 class="dolitmd-stats-card__title"><i class="fas fa-stopwatch"></i><?php echo $langs->trans('TmdStatsDelays'); ?></h3>
                <span class="dolitmd-stats-card__count"><?php echo $delays['nb']; ?></span>
            </header>
            <?php if ($delays['nb'] > 0) : ?>
                <ul class="dolitmd-stats-bars">
                    <?php foreach ($delays['rows'] as $row) : ?>
                        <li>
                            <span class="dolitmd-stats-bars__row">
                                <span class="dolitmd-stats-bars__label"><?php echo dol_escape_htmltag($row['label']); ?></span>
                                <span class="dolitmd-stats-bars__value"><?php echo $row['nb']; ?></span>
                                <svg class="dolitmd-stats-bars__bar" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true">
                                    <rect class="dolitmd-stats-bars__fill dolitmd-stats-bars__fill--delay" width="<?php echo $row['width']; ?>" height="10"></rect>
                                </svg>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <div class="dolitmd-stats-empty"><i class="fas fa-stopwatch"></i><?php echo $langs->trans('TmdStatsNoDone'); ?></div>
            <?php endif; ?>
        </section>

        <?php $late = $stats['late']; ?>
        <section class="dolitmd-stats-card dolitmd-stats-card--third">
            <header class="dolitmd-stats-card__header">
                <h3 class="dolitmd-stats-card__title"><i class="fas fa-exclamation-triangle"></i><?php echo $langs->trans('TmdStatsLateTitle'); ?></h3>
                <?php if ($late['nb'] > 0) : ?>
                    <a class="dolitmd-stats-card__more" href="<?php echo dol_escape_htmltag($late['url']); ?>"><?php echo $langs->trans('TmdStatsSeeAll') . ' (' . $late['nb'] . ')'; ?><i class="fas fa-arrow-right"></i></a>
                <?php endif; ?>
            </header>
            <?php if (!empty($late['rows'])) : ?>
                <ul class="dolitmd-stats-list">
                    <?php foreach ($late['rows'] as $row) : ?>
                        <li class="dolitmd-stats-list__item">
                            <span class="dolitmd-stats-list__main">
                                <span class="dolitmd-stats-list__title"><?php echo $row['link']; ?></span>
                                <span class="dolitmd-stats-list__meta">
                                    <?php echo $row['thirdparty'] !== '' ? $row['thirdparty'] : '<span class="dolitmd-stats-list__subject">' . dol_escape_htmltag($row['subject']) . '</span>'; ?>
                                    <span class="dolitmd-stats-list__date"><?php echo $langs->trans('TmdStatsDueOn', dol_print_date($row['due'], 'day')); ?></span>
                                </span>
                            </span>
                            <span class="dolitmd-stats-list__value dolitmd-stats-list__value--alert"><?php echo $langs->trans('TmdStatsDaysLate', $row['days']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <div class="dolitmd-stats-empty"><i class="fas fa-check-circle"></i><?php echo $langs->trans('TmdStatsNoLate'); ?></div>
            <?php endif; ?>
        </section>

        <section class="dolitmd-stats-card dolitmd-stats-card--half">
            <header class="dolitmd-stats-card__header">
                <h3 class="dolitmd-stats-card__title"><i class="fas fa-building"></i><?php echo $langs->trans('TmdStatsCustomers'); ?></h3>
            </header>
            <?php if (!empty($stats['customers'])) : ?>
                <?php // Fixed layout over the whole card: the table never overflows, no scrolling box to cut its edges ?>
                <table class="dolitmd-stats-table">
                    <thead>
                        <tr>
                            <th><?php echo $langs->trans('ThirdParty'); ?></th>
                            <th class="dolitmd-stats-table__nb right"><?php echo $langs->trans('TmdStatsReceivedShort'); ?></th>
                            <th class="dolitmd-stats-table__nb right"><?php echo $langs->trans('TmdStatsDoneShort'); ?></th>
                            <th class="dolitmd-stats-table__time right"><?php echo $langs->trans('TmdStatsMeanTimeShort'); ?></th>
                            <th class="dolitmd-stats-table__nb right"><?php echo $langs->trans('TmdStatsToInvoiceShort'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['customers'] as $row) : ?>
                            <tr>
                                <td><?php echo $row['thirdparty']; ?></td>
                                <td class="right"><?php echo $row['received']; ?></td>
                                <td class="right"><?php echo $row['done']; ?></td>
                                <td class="right dolitmd-stats-table__time"><?php echo dol_escape_htmltag($row['time']); ?></td>
                                <td class="right"><?php echo $row['toInvoice']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <div class="dolitmd-stats-empty"><i class="fas fa-building"></i><?php echo $langs->trans('TmdStatsNoRequest'); ?></div>
            <?php endif; ?>
        </section>

        <section class="dolitmd-stats-card dolitmd-stats-card--half">
            <header class="dolitmd-stats-card__header">
                <h3 class="dolitmd-stats-card__title"><i class="fas fa-history"></i><?php echo $langs->trans('TmdStatsLastDone'); ?></h3>
                <a class="dolitmd-stats-card__more" href="<?php echo dol_escape_htmltag($stats['kpis'][1]['url']); ?>"><?php echo $langs->trans('TmdStatsSeeAll'); ?><i class="fas fa-arrow-right"></i></a>
            </header>
            <?php if (!empty($stats['lastDone'])) : ?>
                <ul class="dolitmd-stats-feed">
                    <?php foreach ($stats['lastDone'] as $row) : ?>
                        <li class="dolitmd-stats-feed__item">
                            <span class="dolitmd-stats-feed__icon"><i class="fas <?php echo $row['icon']; ?>"></i></span>
                            <span class="dolitmd-stats-feed__main">
                                <span class="dolitmd-stats-feed__label"><?php echo $row['link']; ?><span class="dolitmd-stats-feed__subject"><?php echo dol_escape_htmltag($row['subject']); ?></span></span>
                                <?php if ($row['thirdparty'] !== '') : ?>
                                    <span class="dolitmd-stats-feed__meta"><?php echo $row['thirdparty']; ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="dolitmd-stats-feed__aside">
                                <span class="dolitmd-stats-feed__date"><?php echo dol_print_date($row['date'], 'dayhour', 'tzuserrel'); ?></span>
                                <?php if ($row['user'] !== null) {
                                    echo $row['user']->getNomUrl(-2);
                                } ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <div class="dolitmd-stats-empty"><i class="fas fa-history"></i><?php echo $langs->trans('TmdStatsNoLastDone'); ?></div>
            <?php endif; ?>
        </section>
    </div>
</div>
