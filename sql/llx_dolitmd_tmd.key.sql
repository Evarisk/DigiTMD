-- Copyright (C) 2026 EVARISK <technique@evarisk.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.

ALTER TABLE llx_dolitmd_tmd ADD UNIQUE INDEX uk_dolitmd_tmd_ref (ref, entity);
-- One request per mail: a mail read again (synchronisation started over) never makes a second ticket
ALTER TABLE llx_dolitmd_tmd ADD UNIQUE INDEX uk_dolitmd_tmd_message (message_id, entity);
ALTER TABLE llx_dolitmd_tmd ADD INDEX idx_dolitmd_tmd_status (status);
ALTER TABLE llx_dolitmd_tmd ADD INDEX idx_dolitmd_tmd_fk_soc (fk_soc);
ALTER TABLE llx_dolitmd_tmd ADD INDEX idx_dolitmd_tmd_fk_ticket (fk_ticket);
ALTER TABLE llx_dolitmd_tmd ADD INDEX idx_dolitmd_tmd_entity (entity);
ALTER TABLE llx_dolitmd_tmd ADD INDEX idx_dolitmd_tmd_fk_facture (fk_facture);
ALTER TABLE llx_dolitmd_tmd ADD INDEX idx_dolitmd_tmd_date_due (date_due);
-- Tracking number of the public page of a request: the only secret of that page
ALTER TABLE llx_dolitmd_tmd ADD UNIQUE INDEX uk_dolitmd_tmd_track_id (track_id);
