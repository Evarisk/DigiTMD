/* Copyright (C) 2026 EVARISK <technique@evarisk.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    js/modules/stats.js
 * \ingroup dolitmd
 * \brief   Statistics of the TMD requests: tooltip of the chart of the requests received over twelve months
 */

'use strict';

/**
 * Init stats JS
 *
 * @type {Object}
 */
window.dolitmd.stats = {};

/**
 * Stats init
 *
 * @returns {void}
 */
window.dolitmd.stats.init = function() {
  window.dolitmd.stats.event();
};

/**
 * Stats events
 *
 * @returns {void}
 */
window.dolitmd.stats.event = function() {
  $(document).on('mouseenter', '.dolitmd-stats-chart__hit', window.dolitmd.stats.showTooltip);
  $(document).on('mouseleave', '.dolitmd-stats-chart__hit', window.dolitmd.stats.hideTooltip);
};

/**
 * Requests received in the hovered month, next to the same month one year earlier
 *
 * @returns {void}
 */
window.dolitmd.stats.showTooltip = function() {
  const hit     = $(this);
  const plot    = hit.closest('.dolitmd-stats-chart__plot');
  const tooltip = plot.find('.dolitmd-stats-chart__tooltip');
  const nbMonth = plot.find('.dolitmd-stats-chart__hit').length;
  const index   = parseInt(hit.data('index'), 10);

  plot.find('.dolitmd-stats-chart__hit').removeClass('is-hovered');
  hit.addClass('is-hovered');

  const row = function(label, value, modifier) {
    return $('<span class="dolitmd-stats-chart__tooltip-row">')
      .append($('<span class="dolitmd-stats-chart__tooltip-key">').addClass(modifier).text(label))
      .append($('<span>').text(value));
  };
  tooltip.empty()
    .append($('<strong>').text(hit.data('month') + (hit.data('evolution') ? ' · ' + hit.data('evolution') : '')))
    .append(row(hit.data('title'), hit.data('current'), ''))
    .append(row(hit.data('previous-title'), hit.data('previous'), 'dolitmd-stats-chart__tooltip-key--previous'));

  // The tooltip sits over the middle of its month, kept inside the plot at both ends
  const plotWidth = plot.width();
  const center    = (index + 0.5) / nbMonth * plotWidth;
  const half      = tooltip.outerWidth() / 2 || 90;
  tooltip.css('left', Math.min(Math.max(center, half), plotWidth - half) + 'px');
  tooltip.prop('hidden', false);
};

/**
 * Hide the tooltip of the chart
 *
 * @returns {void}
 */
window.dolitmd.stats.hideTooltip = function() {
  $(this).removeClass('is-hovered');
  $(this).closest('.dolitmd-stats-chart__plot').find('.dolitmd-stats-chart__tooltip').prop('hidden', true);
};
