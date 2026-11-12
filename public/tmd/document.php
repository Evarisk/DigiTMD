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
 * \file    public/tmd/document.php
 * \ingroup dolitmd
 * \brief   A document of a TMD request, for the public page of the request: served only with its tracking number,
 *          and only a file of the tab Documents of that request (named among them, never a path)
 */

if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', 1);
}
if (!defined('NOREQUIREMENU')) {
    define('NOREQUIREMENU', 1);
}
if (!defined('NOREQUIREHTML')) {
    define('NOREQUIREHTML', 1);
}
if (!defined('NOREQUIREAJAX')) {
    define('NOREQUIREAJAX', 1);
}
if (!defined('NOLOGIN')) {
    define('NOLOGIN', 1);
}
if (!defined('NOCSRFCHECK')) {
    define('NOCSRFCHECK', 1);
}
if (!defined('NOIPCHECK')) {
    define('NOIPCHECK', 1);
}
if (!defined('NOBROWSERNOTIF')) {
    define('NOBROWSERNOTIF', 1);
}

// Entity of the request, before Dolibarr loads its setup
$entity = (int) ($_GET['entity'] ?? 1);
if ($entity > 0 && !defined('DOLENTITY')) {
    define('DOLENTITY', $entity);
}

// Load DoliTMD environment
if (file_exists('../../dolitmd.main.inc.php')) {
    require_once __DIR__ . '/../../dolitmd.main.inc.php';
} elseif (file_exists('../../../dolitmd.main.inc.php')) {
    require_once __DIR__ . '/../../../dolitmd.main.inc.php';
} else {
    die('Include of dolitmd main fails');
}

// Load Dolibarr libraries
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

// Load DoliTMD libraries
require_once __DIR__ . '/../../class/tmd.class.php';

// Global variables definitions
global $db;

if (!isModEnabled('dolitmd')) {
    httponly_accessforbidden('Not found', 404);
}

$trackId  = GETPOST('track_id', 'alphanohtml');
$fileName = (string) GETPOST('file', 'none'); // Only compared with the names of the documents, never used as a path

// The request of the tracking number, then the file among its documents by its name: nothing else can be reached
$object = new Tmd($db);
$file   = null;
if ($trackId !== '' && $fileName !== '' && $object->fetchByTrackId($trackId) > 0) {
    foreach ($object->getPublicFiles() as $candidate) {
        if ($candidate['name'] === $fileName) {
            $file = $candidate;
            break;
        }
    }
}
if ($file === null || !is_file($file['fullname']) || !is_readable($file['fullname'])) {
    httponly_accessforbidden('Not found', 404);
}

// PDF and pictures open in the browser, any other file is downloaded. The type comes from the extension, never
// sniffed, and the file may run nothing in the page of the site
$mime   = dol_mimetype($file['name']);
$inline = in_array($mime, ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'], true);
if (!$inline) {
    $mime = 'application/octet-stream';
}
$encodedName = rawurlencode($file['name']);
$asciiName   = str_replace(['"', '\\'], '', dol_string_unaccent($file['name']));

$db->close();

if (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . dol_filesize($file['fullname']));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . $encodedName);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($file['fullname']);
