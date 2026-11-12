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
 * \file    js/modules/tmd.js
 * \ingroup dolitmd
 * \brief   State and pictos done / invoiced of the TMD requests, saved at once (list and card)
 */

'use strict';

/**
 * Init tmd JS
 *
 * @type {Object}
 */
window.dolitmd.tmd = {};

/**
 * Tmd init
 *
 * @returns {void}
 */
window.dolitmd.tmd.init = function() {
  window.dolitmd.tmd.event();
};

/**
 * Tmd events
 *
 * @returns {void}
 */
window.dolitmd.tmd.event = function() {
  $(document).on('change', '.dolitmd-flag-input', window.dolitmd.tmd.saveFlag);
  $(document).on('change', '.dolitmd-state', window.dolitmd.tmd.saveState);
};

/**
 * Token of the page, for the POST
 *
 * @returns {string}
 */
window.dolitmd.tmd.token = function() {
  if (window.saturne && window.saturne.toolbox && typeof window.saturne.toolbox.getToken === 'function') {
    const token = window.saturne.toolbox.getToken();
    if (token) {
      return token;
    }
  }
  return $('input[name="token"]').first().val() || '';
};

/**
 * Message to the user
 *
 * @param   {string}  message Text
 * @param   {boolean} error   An error
 * @returns {void}
 */
window.dolitmd.tmd.notify = function(message, error) {
  if (typeof $.jnotify === 'function' && message) {
    // An error stays until closed, a success goes by itself
    $.jnotify(message, error ? 'error' : 'ok', !!error);
  }
};

/**
 * Send a change of a request; the answer draws it again, the failure calls back
 *
 * @param   {jQuery}   element  Picto or select changed
 * @param   {Object}   data     Data of the POST
 * @param   {Function} onError  Called with the message when it fails
 * @returns {void}
 */
window.dolitmd.tmd.send = function(element, data, onError) {
  $.ajax({
    url: element.data('url'),
    type: 'POST',
    dataType: 'json',
    data: $.extend({id: element.data('id'), token: window.dolitmd.tmd.token()}, data)
  }).done(function(answer) {
    if (!answer || !answer.success) {
      onError(answer && answer.error);
      return;
    }
    window.dolitmd.tmd.refresh(answer);
    window.dolitmd.tmd.notify(answer.message, false);
  }).fail(function(xhr) {
    onError((xhr.responseJSON || {}).error);
  });
};

/**
 * A picto clicked: done or invoiced, set or cancelled (setting invoiced sets done too)
 *
 * @returns {void}
 */
window.dolitmd.tmd.saveFlag = function() {
  const input = $(this);
  const label = input.closest('.dolitmd-flag');
  const value = input.is(':checked') ? 1 : 0;

  label.addClass('dolitmd-flag--saving');
  input.prop('disabled', true);

  window.dolitmd.tmd.send(input, {action: 'set_flag', field: input.data('field'), value: value}, function(error) {
    input.prop('checked', !value).prop('disabled', false);
    label.removeClass('dolitmd-flag--saving').toggleClass('dolitmd-flag--on', !value);
    window.dolitmd.tmd.notify(error || input.data('error'), true);
  });
};

/**
 * A state chosen in the list: the pictos follow
 *
 * @returns {void}
 */
window.dolitmd.tmd.saveState = function() {
  const select   = $(this);
  const previous = String(select.data('state') !== undefined ? select.data('state') : (select.attr('class').match(/dolitmd-state--(\d)/) || [])[1]);

  select.addClass('dolitmd-state--saving').prop('disabled', true);

  window.dolitmd.tmd.send(select, {action: 'set_state', state: select.val()}, function(error) {
    select.val(previous).prop('disabled', false).removeClass('dolitmd-state--saving');
    window.dolitmd.tmd.notify(error || select.data('error'), true);
  });
};

/**
 * State and pictos of the request drawn again, in the list (its row and the cards) and on the card (its banner)
 *
 * @param   {Object} answer Answer of ajax/tmd.php
 * @returns {void}
 */
window.dolitmd.tmd.refresh = function(answer) {
  ['done', 'invoiced'].forEach(function(field) {
    $('.dolitmd-flag-input[data-id="' + answer.id + '"][data-field="' + field + '"]').closest('.dolitmd-flag').replaceWith(answer.flags[field]);
  });
  // The link to the invoice, after the pictos of the list: shown while the request is invoiced
  var flags = $('.dolitmd-flag-input[data-id="' + answer.id + '"][data-field="invoiced"]').closest('.dolitmd-flags');
  flags.find('.dolitmd-invoice-link').remove();
  flags.append(answer.invoice || '');
  $('tr[data-rowid="' + answer.id + '"] td[data-colkey="status"]').html(answer.state);
  // Deadline: a request done is late no more
  $('tr[data-rowid="' + answer.id + '"] td[data-colkey="date_due"]').html(answer.due || '');
  $.each(answer.counts || {}, function(state, count) {
    $('.saturne-kpi-card[data-kpi-id="' + state + '"] .saturne-kpi-card-value').text(count);
  });
  // Card « Late »: red while there are some, grey when none
  if (answer.counts && answer.counts.late !== undefined) {
    $('.saturne-kpi-card[data-kpi-id="late"]').toggleClass('saturne-kpi-card-red', answer.counts.late > 0).toggleClass('saturne-kpi-card-grey', answer.counts.late === 0);
  }
  if ($('.dolitmd-flag-input[data-id="' + answer.id + '"]').closest('.fichecenter').length) {
    $('.statusref').first().html(answer.status);
  }
};
