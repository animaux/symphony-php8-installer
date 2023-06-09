<?php
/**
 * @package email-gateways
 */

/**
 * One of the two core email gateways.
 * Provides simple SMTP functionalities.
 * Supports AUTH LOGIN, SSL and TLS.
 *
 * @author Huib Keemink, Michael Eichelsdoerfer
 */
class SMTPGateway extends EmailGateway
{
    protected $_SMTP;
    protected $_helo_hostname;
    protected $_host;
    protected $_port;
    protected $_protocol = 'tcp';
    protected $_secure = 'no';
    protected $_auth = false;
    protected $_user;
    protected $_pass;
    protected $_envelope_from;

    /**
     * Returns the name, used in the dropdown menu in the preferences pane.
     *
     * @return array
     */
    public static function about()
    {
        return array(
            'name' => __('SMTP'),
        );
    }

    /**
     * Constructor. Sets basic default values based on preferences.
     *
     * @throws EmailValidationException
     */
    public function __construct()
    {
        parent::__construct();
        $this->setConfiguration(Symphony::Configuration()->get('email_smtp'));
    }

    /**
     * Send an email using an SMTP server
     *
     * @throws EmailGatewayException
     * @throws EmailValidationException
     * @throws Exception
     * @return boolean
     */
    public function send()
    {
        $this->validate();

        $settings = array();
        $settings['helo_hostname'] = $this->_helo_hostname;
        if ($this->_auth) {
            $settings['username'] = $this->_user;
            $settings['password'] = $this->_pass;
        }
        $settings['secure'] = $this->_secure;

        try {
            if (!is_a($this->_SMTP, 'SMTP')) {
                $this->_SMTP = new SMTP($this->_host, $this->_port, $settings);
            }

            // Encode recipient names (but not any numeric array indexes)
            $recipients = array();
            foreach ($this->_recipients as $name => $email) {
                // Support Bcc header
                if (isset($this->_header_fields['Bcc']) && $this->_header_fields['Bcc'] == $email) {
                    continue;
                }

                // if the key is not numeric, qEncode the key.
                $name = General::intval($name) > -1 ? General::intval($name) : EmailHelper::qEncode($name);
                $recipients[$name] = $email;
            }

            // Combine keys and values into a recipient list (name <email>, name <email>).
            $recipient_list = EmailHelper::arrayToList($recipients);

            // Encode the subject
            $subject = EmailHelper::qEncode((string)$this->_subject);

            // Build the 'From' header field body
            $from = empty($this->_sender_name)
                    ? $this->_sender_email_address
                    : EmailHelper::qEncode($this->_sender_name) . ' <' . $this->_sender_email_address . '>';

            // Build the 'Reply-To' header field body
            if (!empty($this->_reply_to_email_address)) {
                $reply_to = empty($this->_reply_to_name)
                            ? $this->_reply_to_email_address
                            : EmailHelper::qEncode($this->_reply_to_name) . ' <'.$this->_reply_to_email_address.'>';
            }

            if (!empty($reply_to)) {
                $this->_header_fields = array_merge(
                    $this->_header_fields,
                    array(
                        'Reply-To' => $reply_to,
                    )
                );
            }

            // Build the body text using attachments, html-text and plain-text.
            $this->prepareMessageBody();

            // Build the header fields
            $this->_header_fields = array_merge(
                $this->_header_fields,
                array(
                    'Message-ID'   => sprintf('<%s@%s>', md5(uniqid()), HTTP_HOST),
                    'Date'         => date('r'),
                    'From'         => $from,
                    'Subject'      => $subject,
                    'To'           => $recipient_list,
                    'MIME-Version' => '1.0'
                )
            );

            // Set header fields and fold header field bodies
            foreach ($this->_header_fields as $name => $body) {
                $this->_SMTP->setHeader($name, EmailHelper::fold($body));
            }

            // Send the email command. If the envelope from variable is set, use that for the MAIL command. This improves bounce handling.
            $this->_SMTP->sendMail(is_null($this->_envelope_from)?$this->_sender_email_address:$this->_envelope_from, $this->_recipients, $this->_body);

            if ($this->_keepalive === false) {
                $this->closeConnection();
            }

            $this->reset();
        } catch (SMTPException $e) {
            throw new EmailGatewayException($e->getMessage());
        }

        return true;
    }

    /**
     * Resets the headers, body, subject
     *
     * @return void
     */
    public function reset()
    {
        $this->_header_fields = array();
        $this->_envelope_from = null;
        $this->_recipients = array();
        $this->_subject = null;
        $this->_body = null;
    }

    public function openConnection()
    {
        return parent::openConnection();
    }

    public function closeConnection()
    {
        if (is_a($this->_SMTP, 'SMTP')) {
            try {
                $this->_SMTP->quit();
                return parent::closeConnection();
            } catch (Exception $e) {
            }
        }

        parent::closeConnection();
        return false;
    }

    /**
     * Sets the HELO/EHLO hostanme
     *
     * @param string $helo_hostname
     * @return void
     */
    public function setHeloHostname($helo_hostname = null)
    {
        $this->_helo_hostname = $helo_hostname;
    }

    /**
     * Sets the host to connect to.
     *
     * @param null|string $host (optional)
     * @return void
     */
    public function setHost($host = null)
    {
        if ($host === null) {
            $host = '127.0.0.1';
        }

        if (substr($host, 0, 6) == 'ssl://') {
            $this->_protocol = 'ssl';
            $this->_secure = 'ssl';
            $host = substr($host, 6);
        }
        $this->_host = $host;
    }

    /**
     * Sets the port, used in the connection.
     *
     * @param null|int $port
     * @return void
     */
    public function setPort($port = null)
    {
        if (is_null($port)) {
            $port = ($this->_protocol == 'ssl') ? 465 : 25;
        }

        $this->_port = $port;
    }

    /**
     * Sets the username to use with AUTH LOGIN
     *
     * @param string $user
     * @return void
     */
    public function setUser($user = null)
    {
        $this->_user = $user;
    }

    /**
     * Sets the password to use with AUTH LOGIN
     *
     * @param string $pass
     * @return void
     */
    public function setPass($pass = null)
    {
        $this->_pass = $pass;
    }

    /**
     * Use AUTH login or no auth.
     *
     * @param boolean $auth
     * @return void
     */
    public function setAuth($auth = false)
    {
        $this->_auth = $auth;
    }

    /**
     * Sets the encryption used.
     *
     * @param string $secure
     *  The encryption used. Can be 'ssl', 'tls'. Anything else defaults to
     *  a non secure TCP connection
     * @return void
     */
    public function setSecure($secure = null)
    {
        if ($secure == 'tls') {
            $this->_protocol = 'tcp';
            $this->_secure = 'tls';
        } elseif ($secure == 'ssl') {
            $this->_protocol = 'ssl';
            $this->_secure = 'ssl';
        } else {
            $this->_protocol = 'tcp';
            $this->_secure = 'no';
        }
    }

    /**
     * Sets the envelope_from address. This is only available via the API, as it is an expert-only feature.
     *
     * @since 2.3.1
     * @param null $envelope_from
     * @throws EmailValidationException
     * @return void
     */
    public function setEnvelopeFrom($envelope_from = null)
    {
        if (preg_match('%[\r\n]%', $envelope_from)) {
            throw new EmailValidationException(__('The Envelope From Address can not contain carriage return or newlines.'));
        }

        $this->_envelope_from = $envelope_from;
    }

    /**
     * Sets all configuration entries from an array.
     *
     * @param array $config
     *  All configuration entries stored in a single array.
     *  The array should have the format of the $_POST array created by the preferences HTML.
     * @throws EmailValidationException
     * @since 2.3.1
     * @return void
     */
    public function setConfiguration($config)
    {   
        $config['helo_hostname'] = $config['helo_hostname'] ?? null;
        $config['from_address'] = $config['from_address'] ?? null;
        $config['from_name'] = $config['from_name'] ?? null;
        $config['host'] = $config['host'] ?? null;
        $config['port'] = $config['port'] ?? null;
        $config['secure'] = $config['secure'] ?? null;
        $config['auth'] = $config['auth'] ?? null;
        $config['username'] = $config['username'] ?? null;
        $config['password'] = $config['password'] ?? null;
       
        $this->setHeloHostname($config['helo_hostname']);
        $this->setFrom($config['from_address'], $config['from_name']);
        $this->setHost($config['host']);
        $this->setPort($config['port']);
        $this->setSecure($config['secure']);

        $this->setAuth((int)$config['auth'] === 1);
        $this->setUser($config['username']);
        $this->setPass($config['password']);
    }

    /**
     * Builds the preferences pane, shown in the Symphony backend.
     *
     * @throws InvalidArgumentException
     * @return XMLElement
     */
    public function getPreferencesPane()
    {
        parent::getPreferencesPane();
        $group = new XMLElement('fieldset');
        $group->setAttribute('class', 'settings condensed pickable');
        $group->setAttribute('id', 'smtp');
        $group->appendChild(new XMLElement('legend', __('Email: SMTP')));

        $div = new XMLElement('div');

        $readonly = array('readonly' => 'readonly');

        $label = Widget::Label(__('HELO Hostname'));
        $label->appendChild(Widget::Input('settings[email_smtp][helo_hostname]', General::sanitize($this->_helo_hostname), 'text', $readonly));
        $div->appendChild($label);

        $group->appendChild($div);
        $group->appendChild(new XMLElement('p', __('A fully qualified domain name (FQDN) of your server, e.g. "www.example.com". If left empty, Symphony will attempt to find an IP address for the EHLO/HELO greeting.'), array('class' => 'help')));

        $div = new XMLElement('div');
        $div->setAttribute('class', 'two columns');

        $label = Widget::Label(__('From Name'));
        $label->setAttribute('class', 'column');
        $label->appendChild(Widget::Input('settings[email_smtp][from_name]', General::sanitize($this->_sender_name), 'text', $readonly));
        $div->appendChild($label);

        $label = Widget::Label(__('From Email Address'));
        $label->setAttribute('class', 'column');
        $label->appendChild(Widget::Input('settings[email_smtp][from_address]', General::sanitize($this->_sender_email_address), 'text', $readonly));
        $div->appendChild($label);

        $group->appendChild($div);

        $div = new XMLElement('div');
        $div->setAttribute('class', 'two columns');

        $label = Widget::Label(__('Host'));
        $label->setAttribute('class', 'column');
        $label->appendChild(Widget::Input('settings[email_smtp][host]', General::sanitize($this->_host), 'text', $readonly));
        $div->appendChild($label);

        $label = Widget::Label(__('Port'));
        $label->setAttribute('class', 'column');
        $label->appendChild(Widget::Input('settings[email_smtp][port]', General::sanitize((string) $this->_port), 'text', $readonly));
        $div->appendChild($label);
        $group->appendChild($div);

        $label = Widget::Label();
        $label->setAttribute('class', 'column');
        // To fix the issue with checkboxes that do not send a value when unchecked.
        $options = array(
            array('no',$this->_secure == 'no', __('No encryption')),
            array('ssl',$this->_secure == 'ssl', __('SSL encryption')),
            array('tls',$this->_secure == 'tls', __('TLS encryption')),
        );
        $select = Widget::Select('settings[email_smtp][secure]', $options, $readonly);
        $label->appendChild($select);
        $group->appendChild($label);

        $group->appendChild(new XMLElement('p', __('For a secure connection, SSL and TLS are supported. Please check the manual of your email provider for more details.'), array('class' => 'help')));

        $label = Widget::Label();
        $label->setAttribute('class', 'column');
        // To fix the issue with checkboxes that do not send a value when unchecked.
        $group->appendChild(Widget::Input('settings[email_smtp][auth]', '0', 'hidden'));
        $input = Widget::Input('settings[email_smtp][auth]', '1', 'checkbox', $readonly);

        if ($this->_auth === true) {
            $input->setAttribute('checked', 'checked');
        }

        $label->setValue(__('%s Requires authentication', array($input->generate())));
        $group->appendChild($label);

        $group->appendChild(new XMLElement('p', __('Some SMTP connections require authentication. If that is the case, enter the username/password combination below.'), array('class' => 'help')));

        $div = new XMLElement('div');
        $div->setAttribute('class', 'two columns');

        $label = Widget::Label(__('Username'));
        $label->setAttribute('class', 'column');
        $label->appendChild(Widget::Input('settings[email_smtp][username]', General::sanitize($this->_user), 'text', array_merge($readonly, array('autocomplete' => 'off'))));
        $div->appendChild($label);

        $label = Widget::Label(__('Password'));
        $label->setAttribute('class', 'column');
        $label->appendChild(Widget::Input('settings[email_smtp][password]', General::sanitize($this->_pass), 'password', array_merge($readonly, array('autocomplete' => 'off'))));
        $div->appendChild($label);
        $group->appendChild($div);

        return $group;
    }
}
