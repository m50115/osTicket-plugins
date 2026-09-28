<?php
namespace OstWorkflow;

/**
 * Attachment mechanics shared by the file and thread handlers.
 * Reuses the proven mobile-api approach (PP-09/PP-10): MIME by finfo, key and
 * signature precomputed from the temp file, ACL attachment -> entry -> thread
 * -> ticket|task (RC-6, RC-7), never core download URLs.
 */
final class Attachments {
    /** Load the core classes this helper relies on (lazy, keeps the main file side-effect free). */
    static function boot() {
        require_once(INCLUDE_DIR . 'class.file.php');
        require_once(INCLUDE_DIR . 'class.attachment.php');
        require_once(INCLUDE_DIR . 'class.thread.php');
        require_once(INCLUDE_DIR . 'class.ticket.php');
    }

    /** Always accepted (detected MIME, never the client-declared one). */
    const BASE_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif',
        'application/pdf', 'application/json', 'text/plain', 'text/csv',
    ];
    /** Never accepted, even if the osTicket file-type setting would allow the extension. */
    const DENY_TYPES = [
        'application/x-dosexec', 'application/x-msdownload', 'application/x-executable',
        'application/x-mach-binary', 'application/x-sharedlib', 'application/x-sh', 'application/x-shellscript',
        'application/x-httpd-php', 'text/x-php', 'text/html', 'application/xhtml+xml', 'image/svg+xml',
        'application/javascript', 'text/javascript', 'application/x-msi', 'application/java-archive',
    ];
    const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'bmp'];

    static function maxBytes() {
        global $cfg;
        $plugin = Runtime::intSetting('max_file_bytes', 1048576);
        $core = $cfg ? (int) $cfg->getMaxFileSize() : 0;
        return $core > 0 ? min($plugin, $core) : $plugin;
    }

    static function maxFiles() {
        return max(1, Runtime::intSetting('max_files_per_note', 5));
    }

    /** Text-like extensions libmagic sometimes calls "data" (e.g. one very long line). */
    const TEXT_EXT = ['txt' => 'text/plain', 'csv' => 'text/csv', 'json' => 'application/json'];

    /**
     * Content-based MIME (never the client-declared type). $name only refines the
     * libmagic fallback for valid UTF-8 text without NUL bytes.
     */
    static function detectMime($path, $name = '') {
        $mime = self::finfoMime($path);
        if ($mime === 'application/octet-stream' && isset(self::TEXT_EXT[self::extension($name)])) {
            $sample = (string) file_get_contents($path, false, null, 0, 65536);
            if ($sample !== '' && strpos($sample, "\0") === false && mb_check_encoding($sample, 'UTF-8'))
                return self::TEXT_EXT[self::extension($name)];
        }
        return $mime;
    }

    private static function finfoMime($path) {
        $mime = null;
        if (function_exists('finfo_open') && ($f = finfo_open(FILEINFO_MIME_TYPE))) {
            $mime = finfo_file($f, $path);
            finfo_close($f);
        } elseif (function_exists('mime_content_type')) {
            $mime = mime_content_type($path);
        }
        return strtolower($mime ?: 'application/octet-stream');
    }

    static function extension($name) {
        return strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
    }

    /** Display name (as uploaded) of a file this agent uploaded; null if unknown. */
    static function uploadName($staffId, $fileId) {
        $r = Store::row('SELECT response FROM ' . Store::table() . ' WHERE staff_id=' . (int) $staffId
            . ' AND resource_type=\'file\' AND resource_id=' . Store::esc((string) $fileId) . ' ORDER BY id DESC LIMIT 1');
        $d = $r && $r['response'] ? json_decode($r['response'], true) : null;
        return is_array($d) && isset($d['data']['name']) ? (string) $d['data']['name'] : null;
    }

    /** Client-supplied names are display text only: strip path, control chars, cap length. */
    static function safeName($name) {
        $name = basename(str_replace('\\', '/', (string) $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..')
            $name = 'file';
        return mb_substr($name, 0, 200);
    }

    /**
     * Is a file with this detected MIME and name acceptable?
     * BASE_TYPES always; otherwise the osTicket "allowed file types" setting
     * (extensions like ".doc" or mime patterns like "image/*"), but never a
     * DENY_TYPES mime, and an image/pdf extension must carry matching content.
     */
    static function isAllowed($mime, $name) {
        global $cfg;
        if (in_array($mime, self::DENY_TYPES, true))
            return false;
        $ext = self::extension($name);
        if (in_array($ext, self::IMAGE_EXT, true) && strpos($mime, 'image/') !== 0)
            return false;
        if ($ext === 'pdf' && $mime !== 'application/pdf')
            return false;
        if (in_array($mime, self::BASE_TYPES, true))
            return true;
        $allowed = $cfg ? trim((string) $cfg->getAllowedFileTypes()) : '';
        foreach (preg_split('/[\s,]+/', $allowed, -1, PREG_SPLIT_NO_EMPTY) as $tok) {
            $tok = strtolower($tok);
            if ($tok === '*' || $tok === '.*') return true;
            if ($tok[0] === '.') {
                if ($ext !== '' && $ext === ltrim($tok, '.')) return true;
            } elseif (substr($tok, -2) === '/*') {
                if (strpos($mime, substr($tok, 0, -1)) === 0) return true;
            } elseif ($tok === $mime) {
                return true;
            }
        }
        return false;
    }

    /** Public description of a stored file (optionally as attached to an entry). */
    static function describe(\AttachmentFile $f, $name = null, $inline = false) {
        return [
            'file_id' => (int) $f->getId(),
            'hash'    => $f->getKey(),
            'name'    => $name !== null && $name !== '' ? $name : $f->getName(),
            'size'    => (int) $f->getSize(),
            'type'    => $f->getType() ?: 'application/octet-stream',
            'inline'  => (bool) $inline,
        ];
    }

    /** @return array[] attachments of a thread entry as descriptions */
    static function ofEntry(\ThreadEntry $entry) {
        self::boot();
        $out = [];
        foreach ($entry->attachments as $att) {
            if (!($f = $att->getFile())) continue;
            $out[] = self::describe($f, $att->getFilename(), $att->inline);
        }
        return $out;
    }

    /**
     * May the agent read this file? True when the agent uploaded it (not yet
     * attached), or it is attached to a ticket/task entry the agent can access.
     * @param string|null $name set to the attachment's display name when found
     */
    static function canAccess(\Staff $staff, \AttachmentFile $file, &$name = null) {
        if (Idempotency::ownsFile($staff->getId(), $file->getId()))
            return true;
        self::boot();
        require_once(INCLUDE_DIR . 'class.task.php');
        $atts = \Attachment::objects()->filter(['file_id' => $file->getId(), 'type' => 'H']);
        foreach ($atts as $att) {
            $entry = $att->getObject();
            if (!$entry || !($entry instanceof \ThreadEntry)) continue;
            $thread = $entry->getThread();
            $obj = $thread ? $thread->getObject() : null;
            if (($obj instanceof \Ticket || $obj instanceof \Task) && $obj->checkStaffPerm($staff)) {
                $name = $att->getFilename();
                return true;
            }
        }
        return false;
    }

    /**
     * Validate the `file_ids` input of a write and return the AttachmentFile
     * objects. Every id must exist AND have been uploaded by this agent
     * (POST /files), because the core accepts any id (Architecture §I).
     * @return \AttachmentFile[]
     */
    static function resolve($raw, \Staff $staff, $field = 'file_ids') {
        self::boot();
        if ($raw === null || $raw === []) return [];
        if (!is_array($raw))
            throw ApiError::validation("'$field' must be an array of file ids", $field);
        $ids = [];
        foreach ($raw as $i => $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id)))
                throw ApiError::validation("'$field' must contain integer file ids", $field);
            $ids[(int) $id] = true;
        }
        $ids = array_keys($ids);
        if (count($ids) > self::maxFiles())
            throw new ApiError('validation_failed', 'Too many attachments (max ' . self::maxFiles() . ')', $field,
                ['max_files' => self::maxFiles()]);
        $files = [];
        foreach ($ids as $id) {
            $f = $id > 0 ? \AttachmentFile::lookup($id) : null;
            $mine = $id > 0 && Idempotency::ownsFile($staff->getId(), $id);
            if (!$f && $mine)
                // Uploaded by this agent but no longer there: the core deletes files that stay unattached for a day.
                throw new ApiError('file_expired', "file $id was uploaded but expired before it was attached; upload it again", $field,
                    ['file_id' => $id, 'retention' => 'unattached uploads are deleted after about 1 day']);
            if (!$f || !$mine)
                throw ApiError::validation("file $id is unknown or was not uploaded by this agent", $field, ['file_id' => $id]);
            $files[] = $f;
        }
        return $files;
    }

    /** Shape accepted by ThreadEntry::create()'s `files` var (existing files by id). */
    static function forCreate(array $files, \Staff $staff = null) {
        $out = [];
        foreach ($files as $f) {
            $i = ['id' => $f->getId(), 'key' => $f->getKey()];
            // The core deduplicates identical content into one file row; keep the name
            // this agent uploaded it with on the attachment.
            if ($staff && ($n = self::uploadName($staff->getId(), $f->getId())))
                $i['name'] = $n;
            $out[] = $i;
        }
        return $out;
    }

    /** ids of the files currently attached to an entry. */
    static function attachedIds(\ThreadEntry $entry) {
        $ids = [];
        $rows = \Attachment::objects()->filter(['type' => 'H', 'object_id' => $entry->getId()])->values_flat('file_id');
        foreach ($rows as $r) $ids[(int) $r[0]] = true;
        return array_keys($ids);
    }

    /**
     * Attach the given files to an existing entry, then PROVE they are all
     * attached. Missing ones are retried once; a remaining gap is an error
     * (never report success with fewer attachments, legacy §B-17).
     */
    static function attachAll(\ThreadEntry $entry, array $files, \Staff $staff = null) {
        $have = array_flip(self::attachedIds($entry));
        $todo = [];
        foreach (self::forCreate($files, $staff) as $i)
            if (!isset($have[$i['id']]))
                $todo[] = $i;
        if ($todo)
            $entry->createAttachments($todo);
        self::assertAttached($entry, $files);
    }

    static function assertAttached(\ThreadEntry $entry, array $files) {
        $have = array_flip(self::attachedIds($entry));
        $missing = [];
        foreach ($files as $f)
            if (!isset($have[$f->getId()])) $missing[] = (int) $f->getId();
        if ($missing)
            // The entry exists already: say so, and how to finish the job (never a silent success with fewer files).
            throw new ApiError('attachment_missing', 'The entry was created but some files could not be attached', 'file_ids',
                ['entry_id' => (int) $entry->getId(), 'entry_created' => true, 'missing_file_ids' => $missing,
                 'retry_with' => 'POST /notes/{entry}/files (or PATCH the note) with the missing file_ids']);
    }
}
