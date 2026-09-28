<?php
require_once(INCLUDE_DIR . 'class.forms.php');

class OstWorkflowConfig extends PluginConfig {

    // Bump when PluginConfig keys or the plumbing table change.
    const SCHEMA_VERSION = 1;

    function getOptions() {
        return array(
            'signing_secret' => new PasswordField(array(
                'label'    => __('Token signing secret'),
                'required' => false,
                'hint'     => __('At least 32 random characters. Independent of SECRET_SALT. Rotating it revokes every issued token.'),
                'configuration' => array('size' => 64, 'length' => 128),
            )),
            'token_ttl_days' => new TextboxField(array(
                'label'    => __('Token lifetime (days)'),
                'default'  => '30',
                'required' => true,
                'configuration' => array('size' => 6, 'length' => 4),
            )),
            'default_dept_id' => new TextboxField(array(
                'label'    => __('Default department id for new tickets'),
                'hint'     => __('Empty = the request must send it explicitly (422 otherwise).'),
                'required' => false,
                'configuration' => array('size' => 6, 'length' => 6),
            )),
            'default_topic_id' => new TextboxField(array(
                'label'    => __('Default help topic id for new tickets'),
                'hint'     => __('Empty = the request must send it explicitly (422 otherwise).'),
                'required' => false,
                'configuration' => array('size' => 6, 'length' => 6),
            )),
            'max_files_per_note' => new TextboxField(array(
                'label'    => __('Max attachments per note/reply'),
                'default'  => '5',
                'required' => true,
                'configuration' => array('size' => 4, 'length' => 3),
            )),
            'max_file_bytes' => new TextboxField(array(
                'label'    => __('Max bytes per uploaded file'),
                'default'  => '1048576',
                'required' => true,
                'configuration' => array('size' => 10, 'length' => 10),
            )),
            'min_text_with_pdf' => new TextboxField(array(
                'label'    => __('Minimum text characters when a PDF is attached'),
                'hint'     => __('A note or reply that carries a PDF must explain it to the reader (what it is, what to expect). 0 disables the rule.'),
                'default'  => '15',
                'required' => true,
                'configuration' => array('size' => 4, 'length' => 3),
            )),
            'limit_mail_per_hour' => new TextboxField(array(
                'label'    => __('Max messages e-mailed to customers per agent per hour'),
                'hint'     => __('Public replies with notify and tickets created with notify. Caps the damage of a stolen token. 0 disables the limit.'),
                'default'  => '100',
                'required' => false,
                'configuration' => array('size' => 5, 'length' => 5),
            )),
            'limit_uploads_per_hour' => new TextboxField(array(
                'label'    => __('Max file uploads per agent per hour'),
                'hint'     => __('0 disables the limit.'),
                'default'  => '200',
                'required' => false,
                'configuration' => array('size' => 5, 'length' => 5),
            )),
            'limit_pdf_per_hour' => new TextboxField(array(
                'label'    => __('Max ticket PDFs per agent per hour'),
                'hint'     => __('0 disables the limit.'),
                'default'  => '60',
                'required' => false,
                'configuration' => array('size' => 5, 'length' => 5),
            )),
            'limit_lookups_per_hour' => new TextboxField(array(
                'label'    => __('Max contact/organization searches per agent per hour (agents without directory access)'),
                'hint'     => __('0 disables the limit.'),
                'default'  => '300',
                'required' => false,
                'configuration' => array('size' => 5, 'length' => 5),
            )),
            'trusted_proxies' => new TextboxField(array(
                'label'    => __('Trusted proxy IPs / CIDRs (comma separated)'),
                'hint'     => __('X-Forwarded-For is honoured only from these addresses (used for login rate limiting).'),
                'required' => false,
                'configuration' => array('size' => 60, 'length' => 250),
            )),
            'modules' => new TextboxField(array(
                'label'    => __('Enabled app modules (comma separated)'),
                'default'  => 'tickets,service_orders,pdf_signer,quotes,contacts,support,configuration',
                'required' => false,
                'configuration' => array('size' => 80, 'length' => 250),
            )),
            'brand_name' => new TextboxField(array(
                'label'    => __('Brand name'),
                'required' => false,
                'configuration' => array('size' => 40, 'length' => 60),
            )),
            'brand_color' => new TextboxField(array(
                'label'    => __('Brand color (#RRGGBB)'),
                'required' => false,
                'configuration' => array('size' => 10, 'length' => 7),
            )),
        );
    }

    function pre_save(&$config, &$errors) {
        global $msg;
        $secret = $config['signing_secret'] ?? '';
        if ($secret && strlen($secret) < 32)
            $errors['err'] = __('The signing secret must have at least 32 characters');
        foreach (array('token_ttl_days','max_files_per_note','max_file_bytes') as $k)
            if (isset($config[$k]) && (!ctype_digit((string) $config[$k]) || (int) $config[$k] < 1))
                $errors[$k] = __('Must be a positive integer');
        foreach (array('min_text_with_pdf','limit_mail_per_hour','limit_uploads_per_hour','limit_pdf_per_hour','limit_lookups_per_hour') as $k)
            if (isset($config[$k]) && $config[$k] !== '' && !ctype_digit((string) $config[$k]))
                $errors[$k] = __('Must be 0 or a positive integer');
        foreach (array('default_dept_id','default_topic_id') as $k)
            if (!empty($config[$k]) && !ctype_digit((string) $config[$k]))
                $errors[$k] = __('Must be a numeric id');
        if (!empty($config['brand_color']) && !preg_match('/^#[0-9A-Fa-f]{6}$/', $config['brand_color']))
            $errors['brand_color'] = __('Use the form #RRGGBB');
        if (!$errors)
            $msg = __('Configuration updated successfully');
        return !$errors;
    }
}
