<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\ApiError;
use OstWorkflow\Attachments;
use OstWorkflow\Idempotency;
use OstWorkflow\Request;
use OstWorkflow\Res;
use OstWorkflow\Stream;
use OstWorkflow\Time;

/**
 * Files: one-part-per-request upload (files first, note after: Architecture §H)
 * and Bearer-authenticated download with ACL and thumbnails.
 */
final class Files {
    const HASH = '[A-Za-z0-9_-]+';

    static function routes() {
        return [
            ['POST', '/files', 'upload', ['policy' => 'auth']],
            ['GET',  '/files/(?P<hash>' . self::HASH . ')', 'download', ['policy' => 'auth']],
        ];
    }

    // ------------------------------------------------------------------
    // POST /files   multipart/form-data, field `file` (exactly one part)
    // ------------------------------------------------------------------
    static function upload(Request $req) {
        Attachments::boot();
        if (!$req->isMultipart())
            throw ApiError::validation('Send the file as multipart/form-data in the field "file"', 'file');
        $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len > 0 && !$_POST && !$_FILES)
            // PHP dropped the body: post_max_size / upload limits exceeded, before we could see the file.
            throw new ApiError('payload_too_large', 'The request body exceeds the server limit', 'file',
                ['post_max_size' => ini_get('post_max_size'), 'max_file_bytes' => Attachments::maxBytes()]);

        $parts = $req->files('file');
        if (count($parts) !== 1)
            throw ApiError::validation('Exactly one file per request (field "file")', 'file');
        $f = $parts[0];

        switch ((int) $f['error']) {
        case UPLOAD_ERR_OK: break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new ApiError('too_large', 'The file exceeds the size limit', 'file', ['max_bytes' => Attachments::maxBytes()]);
        case UPLOAD_ERR_NO_FILE:
            throw ApiError::validation('No file was sent', 'file');
        default:
            throw ApiError::validation('The upload did not complete; retry', 'file', ['upload_error' => (int) $f['error']]);
        }
        if (!is_uploaded_file($f['tmp_name']))
            throw ApiError::validation('Invalid upload', 'file');

        $size = (int) filesize($f['tmp_name']);
        $max = Attachments::maxBytes();
        if ($size > $max)
            throw new ApiError('too_large', 'The file exceeds the size limit', 'file', ['max_bytes' => $max, 'size' => $size]);
        if ($size < 1)
            throw ApiError::validation('The file is empty', 'file');

        // The client-declared Content-Type is never trusted: detect from content.
        $name = Attachments::safeName($f['name']);
        $mime = Attachments::detectMime($f['tmp_name'], $name);
        if (!Attachments::isAllowed($mime, $name))
            throw new ApiError('unsupported_type', 'File type not allowed', 'file', ['detected_type' => $mime, 'name' => $name]);

        // Key + signature precomputed from the temp file, otherwise create() stores
        // an unservable file (R-C10, RC-6).
        list($key, $sig) = \AttachmentFile::_getKeyAndHash($f['tmp_name'], true);
        $info = [
            'name' => $name, 'type' => $mime, 'size' => $size, 'tmp_name' => $f['tmp_name'],
            'error' => UPLOAD_ERR_OK, 'key' => $key, 'signature' => $sig,
        ];
        $file = \AttachmentFile::create($info);   // deduplicates by signature+size
        if (!$file)
            throw new \RuntimeException('AttachmentFile::create failed');

        // Ownership proof: a later note/reply may only reference files this agent uploaded.
        Idempotency::record('file', $file->getId());

        // ->created is still an SqlFunction on a freshly created row: read it back.
        $row = \OstWorkflow\Store::row('SELECT created FROM ' . FILE_TABLE . ' WHERE id=' . (int) $file->getId());
        return Res::created(array_merge(Attachments::describe($file, $name), [
            'sha256'  => hash_file('sha256', $f['tmp_name']),
            'created' => Time::iso($row['created'] ?? null),
        ]));
    }

    // ------------------------------------------------------------------
    // GET /files/{hash}[?s=<px>][&inline=1]
    // ------------------------------------------------------------------
    static function download(Request $req) {
        Attachments::boot();
        $hash = (string) $req->param('hash');
        if (strlen($hash) > 64)
            throw ApiError::validation('Invalid file hash', 'hash');
        $file = \AttachmentFile::lookupByHash($hash);
        if (!$file)
            throw ApiError::notFound('file');

        $name = null;
        if (!Attachments::canAccess($req->staff, $file, $name))
            throw new ApiError('forbidden', 'You cannot access this file');
        $name = Attachments::safeName($name ?: $file->getName());

        $type = strtolower($file->getType() ?: 'application/octet-stream');
        $scale = $req->q('s');
        if ($scale !== null) {
            if (!ctype_digit((string) $scale) || (int) $scale < 16 || (int) $scale > 2048)
                throw ApiError::validation("'s' must be an integer between 16 and 2048", 's');
            if (strpos($type, 'image/') !== 0 || !extension_loaded('gd'))
                throw ApiError::validation('Thumbnails are only available for images', 's');
        }

        $etag = '"' . $file->getSignature(true) . ($scale !== null ? '-s' . (int) $scale : '') . '"';
        $base = [
            'ETag'                    => $etag,
            'Cache-Control'           => 'private, max-age=86400',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Content-Type-Options'  => 'nosniff',
        ];
        $inm = (string) $req->header('If-None-Match');
        if ($inm !== '' && trim($inm) === $etag)
            return new Stream(function () {}, $base, 304);

        if ($scale !== null) {
            $png = self::thumbnail($file, (int) $scale);
            if ($png === null)
                throw ApiError::validation('The image could not be decoded', 's');
            return new Stream(function () use ($png) { echo $png; }, $base + [
                'Content-Type' => 'image/png', 'Content-Length' => (string) strlen($png),
                'Content-Disposition' => self::disposition('inline', pathinfo($name, PATHINFO_FILENAME) . '.png'),
            ]);
        }

        // Only raster images/PDF may render inline, and only if asked; everything else is a download.
        $inlineOk = $req->q('inline') === '1'
            && (in_array($type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true) || $type === 'application/pdf');
        $size = (int) $file->getSize();
        $head = $base + [
            'Content-Type'        => $type,
            'Accept-Ranges'       => 'bytes',
            'Content-Disposition' => self::disposition($inlineOk ? 'inline' : 'attachment', $name),
        ];

        // Single byte range (resume an interrupted download, read a PDF's tail): `Range: bytes=a-b`, `a-`, `-n`.
        $range = trim((string) $req->header('Range'));
        $ifRange = trim((string) $req->header('If-Range'));
        if ($range !== '' && ($ifRange === '' || $ifRange === $etag) && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') { $start = max(0, $size - (int) $m[2]); $end = $size - 1; }
            else { $start = (int) $m[1]; $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1); }
            if ($size === 0 || $start >= $size || $start > $end)
                return new Stream(function () {}, $head + ['Content-Range' => 'bytes */' . $size, 'Content-Length' => '0'], 416);
            $bk = $file->open();
            return new Stream(function () use ($bk, $start, $end) {
                Stream::copyRange($bk, $start, $end);
            }, $head + ['Content-Range' => sprintf('bytes %d-%d/%d', $start, $end, $size), 'Content-Length' => (string) ($end - $start + 1)], 206);
        }

        $bk = $file->open();
        return new Stream(function () use ($bk) {
            @ini_set('zlib.output_compression', 'Off');
            $bk->passthru();   // chunked read: no full-file buffer in memory (PP-10)
        }, $head + ['Content-Length' => (string) $size]);
    }

    /** RFC 6266 header: ASCII fallback + UTF-8 filename* (legacy §B-18). */
    private static function disposition($kind, $name) {
        $ascii = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);
        return sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $kind, $ascii, rawurlencode($name));
    }

    /** PNG thumbnail (longest side = $scale) or null when the data is not a decodable image. */
    private static function thumbnail(\AttachmentFile $file, $scale) {
        $img = @imagecreatefromstring($file->getData());
        if (!$img) return null;
        $w = imagesx($img); $h = imagesy($img);
        if ($scale >= max($w, $h)) {
            $tw = $w; $th = $h;
        } elseif ($w >= $h) {
            $tw = $scale; $th = max(1, (int) round($h * $scale / $w));
        } else {
            $th = $scale; $tw = max(1, (int) round($w * $scale / $h));
        }
        $out = imagecreatetruecolor($tw, $th);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
        ob_start();
        imagepng($out);
        return ob_get_clean();
    }
}
