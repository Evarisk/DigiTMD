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
 * \file    js/dolitmd.js
 * \ingroup dolitmd
 * \brief   JavaScript file for module DoliTMD: init of its modules (js/modules/*.js)
 */

'use strict';

if (!window.dolitmd) {
  /**
   * Init DoliTMD JS
   *
   * @type {Object}
   */
  window.dolitmd = {};

  /**
   * Modules already started
   *
   * @type {Boolean}
   */
  window.dolitmd.scriptsLoaded = false;
}

if (!window.dolitmd.scriptsLoaded) {
  /**
   * Start every module of DoliTMD; one that fails never stops the others
   *
   * @returns {void}
   */
  window.dolitmd.init = function() {
    if (window.dolitmd.scriptsLoaded) {
      return;
    }
    Object.keys(window.dolitmd).forEach(function(key) {
      const module = window.dolitmd[key];
      if (module && typeof module === 'object' && typeof module.init === 'function') {
        try {
          module.init();
        } catch (error) {
          console.error('DoliTMD: ' + key, error);
        }
      }
    });
    window.dolitmd.scriptsLoaded = true;
  };

  $(document).ready(window.dolitmd.init);
}
