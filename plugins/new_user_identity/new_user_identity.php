<?php
/**
 * New user identity
 *
 * Populates a new user's default identity from LDAP on their first visit.
 *
 * This plugin requires that a working public_ldap directory be configured.
 *
 * @version @package_version@
 * @author Kris Steinhoff
 * @license GNU GPLv3+
 */
class new_user_identity extends rcube_plugin
{
    public $task = 'login';

    private $ldap;

    function init()
    {
        $rcmail = rcmail::get_instance();
        $this->load_config();

        $this->add_hook('user_create', array($this, 'lookup_user_name'));
        if ($rcmail->config->get('new_user_identity_onlogin')) {
            $this->add_hook('login_after', array($this, 'login_after'));
        }
    }

    function lookup_user_name($args)
    {
        if ($this->init_ldap($args['host'])) {
            $results = $this->ldap->search('*', $args['user'], true);

            if (count($results->records) == 1) {
                $user_name  = is_array($results->records[0]['name']) ? $results->records[0]['name'][0] : $results->records[0]['name'];
                $user_email = is_array($results->records[0]['email']) ? $results->records[0]['email'][0] : $results->records[0]['email'];

                $args['user_name']  = $user_name;
                $args['email_list'] = array();

                if (!$args['user_email'] && strpos($user_email, '@')) {
                    $args['user_email'] = rcube_utils::idn_to_ascii($user_email);
                }

                foreach (array_keys($results[0]) as $key) {
                    if (!preg_match('/^email($|:)/', $key)) {
                        continue;
                    }

                    foreach ((array) $results->records[0][$key] as $alias) {
                        if (strpos($alias, '@')) {
                            $args['email_list'][] = rcube_utils::idn_to_ascii($alias);
                        }
                    }
                }

            }
        }

        return $args;
    }

    function login_after($args)
    {
        $rcmail = rcmail::get_instance();

        $identities   = $rcmail->user->list_identities();
        $ldap_entery  = $this->lookup_user_name(array('user' => $rcmail->user->data['username'],
            'host' => $rcmail->user->data['mail_host']));

        foreach ($ldap_entery['email_list'] as $email)
        {
            foreach($identities as $identity) {
                if ($identity['email'] == $email ) {
                    continue 2;
                }
            }

            $plugin = $rcmail->plugins->exec_hook('identity_create', array(
                'login' => true,
                'record' => array('user_id' => $rcmail->user->ID, 'standard' => 0,
                    'email' => $email, 'name' => $ldap_entery['user_name']),
            ));

            if (!$plugin['abort'] && $plugin['record']['email']) {
                $rcmail->user->insert_identity($plugin['record']);
            }
        }
        return $args;
    }

    private function init_ldap($host)
    {
        if ($this->ldap) {
            return $this->ldap->ready;
        }

        $rcmail = rcmail::get_instance();
        $this->load_config();

        $addressbook = $rcmail->config->get('new_user_identity_addressbook');
        $ldap_config = (array)$rcmail->config->get('ldap_public');
        $match       = $rcmail->config->get('new_user_identity_match');

        if (empty($addressbook) || empty($match) || empty($ldap_config[$addressbook])) {
            return false;
        }

        $this->ldap = new new_user_identity_ldap_backend(
            $ldap_config[$addressbook],
            $rcmail->config->get('ldap_debug'),
            $rcmail->config->mail_domain($host),
            $match);

        return $this->ldap->ready;
    }
}

class new_user_identity_ldap_backend extends rcube_ldap
{
    function __construct($p, $debug, $mail_domain, $search)
    {
        parent::__construct($p, $debug, $mail_domain);
        $this->prop['search_fields'] = (array)$search;
    }
}
