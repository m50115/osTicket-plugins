<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\ApiError;
use OstWorkflow\Request;
use OstWorkflow\Res;

/**
 * Dynamic form definitions (read-only): what fields a new ticket, contact or
 * organization asks for. The app builds its dynamic forms from these.
 * Values of existing objects are served by the ticket/user/organization
 * handlers, not here.
 */
final class Forms {
    const MAX_CHOICES = 500;

    static function routes() {
        return [
            ['GET', '/forms/ticket',                    'ticket',       ['policy' => 'auth']],
            ['GET', '/forms/user',                      'user',         ['policy' => 'auth']],
            ['GET', '/forms/organization',              'organization', ['policy' => 'auth']],
            ['GET', '/topics/(?P<id>\d+)/forms',        'topicForms',   ['policy' => 'auth']],
        ];
    }

    /** GET /forms/ticket — the default "Ticket Details" form (TicketForm, class.dynamic_forms.php:530). */
    static function ticket(Request $req) {
        require_once(INCLUDE_DIR . 'class.dynamic_forms.php');
        return Res::ok(self::form(self::pick(\TicketForm::objects(), 'ticket')));
    }

    /** GET /forms/user — the contact form (UserForm::getUserForm, class.dynamic_forms.php:487). */
    static function user(Request $req) {
        require_once(INCLUDE_DIR . 'class.dynamic_forms.php');
        return Res::ok(self::form(\UserForm::getUserForm() ?: self::missing('user')));
    }

    /** GET /forms/organization — OrganizationForm::getDefaultForm (class.organization.php:672). */
    static function organization(Request $req) {
        require_once(INCLUDE_DIR . 'class.dynamic_forms.php');
        require_once(INCLUDE_DIR . 'class.organization.php');
        return Res::ok(self::form(\OrganizationForm::getDefaultForm() ?: self::missing('organization')));
    }

    /**
     * GET /topics/{id}/forms — forms a ticket of this topic asks at creation
     * (Topic::getForms, class.topic.php:174), with the fields the topic
     * disabled already removed. Includes the default ticket form when the
     * topic attaches it (it normally does).
     */
    static function topicForms(Request $req) {
        require_once(INCLUDE_DIR . 'class.topic.php');
        $topic = \Topic::lookup($req->intParam('id'));
        if (!$topic) throw ApiError::notFound('topic');
        // same visibility as GET /topics
        $visible = $req->staff->getTopicNames(false, true);
        if (!array_key_exists($topic->getId(), $visible))
            throw new ApiError('forbidden', 'You cannot access this help topic');

        $forms = [];
        foreach ($topic->getForms() as $f)
            $forms[] = self::form($f);
        return Res::ok([
            'topic' => Catalogs::topic($topic, $visible[$topic->getId()]),
            'forms' => $forms,
        ]);
    }

    // ------------------------------------------------------------------

    private static function pick($qs, $what) {
        $f = $qs->first();
        return $f ?: self::missing($what);
    }

    private static function missing($what) {
        throw ApiError::notFound("$what form");
    }

    /** @param \DynamicForm $form */
    static function form($form) {
        $fields = [];
        foreach ($form->getFields() as $f) {
            $row = self::field($f);
            if ($row) $fields[] = $row;
        }
        usort($fields, function ($a, $b) { return [$a['sort'], $a['id']] <=> [$b['sort'], $b['id']]; });
        return [
            'id'           => (int) $form->getId(),
            'type'         => $form->type,
            'title'        => $form->getTitle(),
            'instructions' => $form->getInstructions() ?: null,
            'fields'       => $fields,
        ];
    }

    /** Field definition, tolerant of the field type (every accessor is optional). */
    static function field($f) {
        $id = (int) $f->get('id');
        $row = [
            'id'    => $id,
            'name'  => $f->get('name'),
            'label' => $f->getLabel(),
            'type'  => $f->get('type'),
            'hint'  => $f->get('hint') ?: null,
            'sort'  => (int) $f->get('sort'),
            'required_staff'  => self::flag($f, 'isRequiredForStaff'),
            'required_users'  => self::flag($f, 'isRequiredForUsers'),
            'visible_staff'   => self::flag($f, 'isVisibleToStaff'),
            'visible_users'   => self::flag($f, 'isVisibleToUsers'),
            'editable_staff'  => self::flag($f, 'isEditableToStaff'),
            'editable_users'  => self::flag($f, 'isEditableToUsers'),
            'has_data'        => self::flag($f, 'hasData'),
        ];
        $conf = self::configuration($f);
        $row['default'] = (array_key_exists('default', $conf) && $conf['default'] !== '') ? $conf['default'] : null;
        $row['configuration'] = $conf;
        $row['choices'] = self::choices($f);
        return $row;
    }

    private static function flag($f, $method) {
        if (!method_exists($f, $method) && !method_exists($f, '__call'))
            return null;
        try {
            return (bool) $f->$method();
        } catch (\Throwable $t) {
            return null;
        }
    }

    /** Scalar configuration entries only (no objects, no widget internals). */
    private static function configuration($f) {
        try {
            $c = method_exists($f, 'getConfiguration') ? $f->getConfiguration() : [];
        } catch (\Throwable $t) {
            return [];
        }
        $out = [];
        foreach ((array) $c as $k => $v) {
            if (is_scalar($v) || $v === null)
                $out[$k] = $v;
            elseif (is_array($v) && !array_filter($v, function ($x) { return !is_scalar($x); }))
                $out[$k] = $v;
        }
        return $out;
    }

    /** [{value, label}] for list-like fields (choices, lists, priority, state, ...), else null. */
    private static function choices($f) {
        if (!method_exists($f, 'getChoices'))
            return null;
        try {
            $c = $f->getChoices();
        } catch (\Throwable $t) {
            return null;
        }
        if (!is_array($c) || !$c)
            return null;
        $out = [];
        foreach ($c as $value => $label) {
            if (is_array($label)) $label = isset($label['name']) ? $label['name'] : (string) reset($label);
            $out[] = ['value' => is_int($value) || ctype_digit((string) $value) ? (int) $value : (string) $value,
                      'label' => (string) $label];
            if (count($out) >= self::MAX_CHOICES) break;
        }
        return $out;
    }
}
