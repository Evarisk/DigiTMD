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

-- Category of a request: ADR (road), IATA (air) or IMDG (sea). Its index here, after the column: the .key.sql files run
-- before this one, on a table still without it
ALTER TABLE llx_dolitmd_tmd ADD COLUMN category varchar(8) AFTER label;
ALTER TABLE llx_dolitmd_tmd ADD INDEX idx_dolitmd_tmd_category (category);
