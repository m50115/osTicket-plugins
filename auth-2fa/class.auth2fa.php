<?php
require_once INCLUDE_DIR . 'class.export.php';

class Auth2FABackend extends TwoFactorAuthenticationBackend {
    static $id = "auth.agent";
    static $name = "Authenticator";

    static $desc = /* @trans */ 'Verification codes are located in the Authenticator app of your choice on your phone';
    static $custom_issuer;

    var $secretKey;

    protected function getSetupOptions() {
        global $thisstaff;

        $auth2FA = new Auth2FABackend;
        // SEC-01 R04: no QR image from a third-party service (the old <img> sent the secret to api.qrserver.com).
        // Manual entry: the key and an otpauth:// link (opens the authenticator app on a phone); nothing leaves the server.
        $secretKey = $auth2FA->getSecretKey($thisstaff);
        if ($auth2FA->validateQRCode($thisstaff)) {
            $h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
            return array(
                '' => new FreeTextField(array(
                    'configuration' => array(
                        'content' => sprintf(
                            '<input type="hidden" name="email" value="%s" />
                            <em>%s</em>
                            </br>
                            <p>%s<br/><code style="font-size:1.2em;letter-spacing:0.1em">%s</code></p>
                            <p>%s <code>%s</code> &middot; %s <code>%s</code> &middot; %s</p>
                            <p><a href="%s">%s</a></p>',
                            $h($thisstaff->getEmail()),
                            $h(__('Add this account to the Authenticator application on your phone by entering the key below (time-based). If you lose it, a helpdesk Administrator will need to reset your 2FA configuration.')),
                            $h(__('Key')), $h(trim(chunk_split($secretKey, 4, ' '))),
                            $h(__('Account')), $h($thisstaff->getEmail()), $h(__('Issuer')), $h(self::issuer()),
                            $h(__('6 digits, every 30 seconds')),
                            $h($auth2FA->getOtpAuthUri($thisstaff)), $h(__('Open in the Authenticator app (on this phone)'))),
                    )
                )),
            );
        }
    }

    protected function getInputOptions() {
        return array(
            'token' => new TextboxField(array(
                'id'=>1, 'label'=>__('Verification Code'), 'required'=>true, 'default'=>'',
                'validator'=>'number',
                'hint'=>__('Please enter the code from your Authenticator app'),
                'configuration'=>array(
                    'size'=>40, 'length'=>40,
                    'autocomplete' => 'one-time-code',
                    'inputmode' => 'numeric',
                    'pattern' => '[0-9]*',
                    'validator-error' => __('Invalid Code format'),
                    ),
            )),
        );
    }

    function validate($form, $user) {
        // Make sure form is valid and token exists
        if (!($form->isValid()
                    && ($clean=$form->getClean())
                    && $clean['token']))
            return false;

        // SEC-01 R02/R05/R06: persistent per-agent failure counter with lockout, single use of every code, serialized
        // per agent (checkAgentCode). A lockout ends the pending login like the core's own "Too many attempts".
        $r = self::checkAgentCode($user, $clean['token']);
        if (!$r['ok'] && $r['retry_after'] > 0)
            throw new ExpiredOTP(__('Too many attempts'));
        if (!$r['ok'])
            return false;

        // upstream validation might throw an exception due to expired token
        // or too many attempts (timeout). It's the responsibility of the
        // caller to catch and handle such exceptions.
        $secretKey = $this->getSecretKey();
        if (!$this->_validate($secretKey))
            return false;

        // Validator doesn't do house cleaning - it's our responsibility
        $this->onValidate($user);

        return true;
    }

    // ------------------------------------------------------------------
    // SEC-01 hardening (BestCare fork; delta inside the plugin only, osTicket core untouched)
    // ------------------------------------------------------------------

    /** Per-agent guard state, a native staff config item next to the secret (NOT inside it: the secret item is hashed
     *  into third-party token fingerprints and must not change on every attempt). JSON {f: failures since the last
     *  accepted code, u: locked until (unix), s: last accepted time step, p: password fingerprint}. */
    const GUARD_KEY = 'auth.agent.guard';
    /** Longest lock (seconds). Locks grow from staff_login_timeout and double with every further failure. */
    const LOCK_CAP = 86400;

    /**
     * Checks a verification code for $staff with the bundled Sonata library and the native policy
     * (staff_max_logins / staff_login_timeout), shared by the staff panel (validate) and any other caller (e.g. the
     * Workflow API), so every channel draws from ONE budget:
     *  - failures are counted persistently per agent; more than staff_max_logins locks the agent's code step for
     *    staff_login_timeout, and every further failure doubles the lock (cap LOCK_CAP). The counter is reset only by
     *    an accepted code or a password change (an admin password reset is the recovery path). Attempts during a lock
     *    are not evaluated.
     *  - a code is accepted only for a time step newer than the last accepted one (RFC 6238 §5.2: no replay).
     *  - serialized per agent with MySQL GET_LOCK (no table); if the lock cannot be taken the code is NOT checked.
     * @return array ['ok' => bool, 'retry_after' => seconds the code step stays locked (0 = not locked)]
     */
    static function checkAgentCode($staff, $code) {
        global $cfg, $ost;
        $code = preg_replace('/\s+/', '', (string) $code);
        $id = (int) $staff->getId();
        $conf = $staff->get2FAConfig(static::$id);
        $secret = is_array($conf) ? (string) ($conf['config']['key'] ?? '') : '';
        if (!$id || $secret === '')
            return array('ok' => false, 'retry_after' => 0);

        $lock = substr('ost2fa.' . md5((defined('DBNAME') ? DBNAME : '') . '|' . TABLE_PREFIX) . '.' . $id, 0, 64);
        $res = db_query('SELECT GET_LOCK(' . db_input($lock) . ', 10)');
        if (!$res || (int) db_result($res) !== 1)
            return array('ok' => false, 'retry_after' => 5);   // fail closed: not evaluated
        try {
            $store = new Config('staff.' . $id);
            $g = JsonDataParser::decode((string) $store->get(self::GUARD_KEY));
            if (!is_array($g)) $g = array();
            $fp = substr(hash_hmac('sha256', 'pw|' . $id . '|' . (string) $staff->getPasswd(), SECRET_SALT), 0, 16);
            if (($g['p'] ?? '') !== $fp)
                $g = array('f' => 0, 'u' => 0, 's' => (int) ($g['s'] ?? 0));
            $g['p'] = $fp;
            $now = time();
            if ((int) ($g['u'] ?? 0) > $now)
                return array('ok' => false, 'retry_after' => (int) $g['u'] - $now);

            $step = self::acceptedStep($secret, $code, $now);
            if ($step && $step > (int) ($g['s'] ?? 0)) {
                $store->set(self::GUARD_KEY, JsonDataEncoder::encode(array('f' => 0, 'u' => 0, 's' => $step, 'p' => $fp)));
                return array('ok' => true, 'retry_after' => 0);
            }

            $g['f'] = (int) ($g['f'] ?? 0) + 1;
            $max = max(1, (int) ($cfg ? $cfg->getStaffMaxLogins() : 4));
            $base = max(60, (int) ($cfg ? $cfg->getStaffLoginTimeout() : 120));
            $g['u'] = 0;
            if ($g['f'] > $max) {
                $g['u'] = $now + (int) min(self::LOCK_CAP, $base * pow(2, min(16, $g['f'] - $max - 1)));
                if ($ost)
                    $ost->logWarning(sprintf(_S('Excessive 2FA code attempts (%s)'), $staff->getUserName()),
                        sprintf("%s: %s\n%s: %d\n%s: %d s", _S('Username'), $staff->getUserName(), _S('Attempts'),
                            $g['f'], _S('Timeout'), $g['u'] - $now),
                        $cfg && $cfg->alertONLoginError() == 1);
            }
            $store->set(self::GUARD_KEY, JsonDataEncoder::encode($g));
            return array('ok' => false, 'retry_after' => max(0, (int) $g['u'] - $now));
        } finally {
            db_query('SELECT RELEASE_LOCK(' . db_input($lock) . ')');
        }
    }

    /** Time step (unix/30) whose code equals $code within the same window as Sonata checkCode (discrepancy 1), or 0.
     *  Uses the library's own getCode (no TOTP engine here). */
    private static function acceptedStep($secret, $code, $now) {
        if (!preg_match('/^[0-9]{6}$/', $code))
            return 0;
        $ga = new \Sonata\GoogleAuthenticator\GoogleAuthenticator();
        $hit = 0;
        foreach (array(-1, 0, 1) as $d) {
            $t = $now + 30 * $d;
            if (hash_equals($ga->getCode($secret, new \DateTimeImmutable('@' . $t)), $code))
                $hit = max($hit, intdiv($t, 30));
        }
        return $hit;
    }

    static function issuer() {
        return preg_replace('/[^A-Za-z0-9]/', '', self::$custom_issuer ?: __('osTicket'));
    }

    /** otpauth:// URI (Key Uri Format) for manual entry / a local authenticator app; never sent to a third party. */
    function getOtpAuthUri($staff) {
        $issuer = self::issuer();
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $staff->getEmail())
            . '?secret=' . rawurlencode($this->getSecretKey($staff)) . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }

    function send($user) {
        global $cfg;

        // Get backend configuration for this user
        if (!$cfg || !($info = $user->get2FAConfig($this->getId())))
            return false;

        // get configuration
        $config = $info['config'];

        // Generate Secret Key
        if (!$this->secretKey)
            $this->secretKey = $this->getSecretKey($user);

        $this->store($this->secretKey);

        return true;
    }

    function store($secretKey) {
       global $thisstaff;

       $store =  &$_SESSION['_2fa'][$this->getId()];
       $store = ['otp' => $secretKey, 'time' => time(), 'strikes' => 0];

       if ($thisstaff) {
           $config = array('config' => array('key' => $secretKey, 'external2fa' => true));
           $_config = new Config('staff.'.$thisstaff->getId());
           $_config->set($this->getId(), JsonDataEncoder::encode($config));
           $thisstaff->_config = $_config->getInfo();
           $errors['err'] = '';
       }

       return $store;
    }

    function validateLoginCode($code) {
        $auth2FA = new \Sonata\GoogleAuthenticator\GoogleAuthenticator();
        $secretKey = $this->getSecretKey();

        return $auth2FA->checkCode($secretKey, $code);
    }

    function getSecretKey($staff=false) {
        if (!$staff) {
            $s = StaffAuthenticationBackend::getUser();
            $staff = Staff::lookup($s->getId());
        }

        if (!$token = ConfigItem::getConfigsByNamespace('staff.'.$staff->getId(), static::$id)) {
            $auth2FA = new \Sonata\GoogleAuthenticator\GoogleAuthenticator();
            $this->secretKey = $auth2FA->generateSecret();
            $this->store($this->secretKey);
        }

        $key = $token->value ?: $this->secretKey;
        if (strpos($key, 'config')) {
            $key = json_decode($key, true);
            $key = $key['config']['key'];
        }

        return $key;
    }

    /** SEC-01 R04: kept for compatibility, but it no longer builds a third-party QR image URL (that sent the secret
     *  to api.qrserver.com); it returns the otpauth:// URI for local use. */
    function getQRCode($staff=false) {
        return $this->getOtpAuthUri($staff);
    }

    function validateQRCode($staff=false) {
        $auth2FA = new \Sonata\GoogleAuthenticator\GoogleAuthenticator();
        $secretKey = $this->getSecretKey($staff);
        $code = self::getCode();

        return $auth2FA->checkCode($secretKey, $code);
    }

    static function getCode() {
        $auth2FA = new \Sonata\GoogleAuthenticator\GoogleAuthenticator();
        $self = new Auth2FABackend();
        $secretKey = $self->getSecretKey();

        return $auth2FA->getCode($secretKey);
    }
}
