<?php
/**
 * Twilio Plugin for phplist.
 *
 * This file is a part of Twilio Plugin.
 *
 * This plugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This plugin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * @category  phplist
 *
 * @author    Duncan Cameron
 * @copyright 2017 Duncan Cameron
 * @license   http://www.gnu.org/licenses/gpl.html GNU General Public License, Version 3
 */

/**
 * Registers the plugin with phplist.
 */
class Twilio extends phplistPlugin implements EmailSender
{
    const VERSION_FILE = 'version.txt';
    const PLUGIN = 'Twilio';

    private $campaigns = [];
    private $currentSubscriber = null;
    private $sendSuccess = 0;
    private $sendFail = 0;
    private $logger;
    /*
     *  Inherited variables
     */
    public $name = 'Twilio Plugin';
    public $authors = 'Duncan Cameron';
    public $description = 'Send SMS text messages through Twilio';
    public $settings = array(
        'twilio_sid' => array(
          'description' => 'The Twilio Account SID',
          'type' => 'text',
          'value' => '',
          'allowempty' => false,
          'category' => 'Twilio',
        ),
        'twilio_auth_token' => array(
          'description' => 'The Twilio Auth Token',
          'type' => 'text',
          'value' => '',
          'allowempty' => false,
          'category' => 'Twilio',
        ),
        'twilio_default_from' => array(
          'description' => 'Default From number to be used for an SMS text',
          'type' => 'text',
          'value' => '',
          'allowempty' => false,
          'category' => 'Twilio',
        ),
        'twilio_phone_attribute' => array(
          'description' => 'The ID of the attribute that holds the subscribers phone number',
          'type' => 'text',
          'value' => '',
          'allowempty' => false,
          'category' => 'Twilio',
        ),
        'twilio_prefer_attribute' => array(
          'description' => 'The ID of the "prefer SMS" checkbox attribute',
          'type' => 'text',
          'value' => '',
          'allowempty' => false,
          'category' => 'Twilio',
        ),
        'twilio_phone_range' => array(
          'description' => 'Minimum:maximum number of digits in a phone number',
          'type' => 'text',
          'value' => '10:10',
          'allowempty' => false,
          'category' => 'Twilio',
        ),
        'twilio_country_code' => array(
          'description' => 'The country code to be prepended to each phone number',
          'type' => 'text',
          'value' => '',
          'allowempty' => true,
          'category' => 'Twilio',
        ),
    );

    private function sendNonSms($mailer, $header, $body)
    {
        if (defined('TWILIO_DEV') && TWILIO_DEV) {
            return $mailer->localSpoolSend($header, $body);
        }

        return $mailer->amazonSesSend($header, $body);
    }

    private function transformPhoneNumber($number)
    {
        $cleaned = preg_replace('/[^\d]/', '', $number);

        if (!$cleaned) {
            return false;
        }

        if ($number[0] == '!') {
            return false;
        }

        if ($number[0] == '+') {
            return '+' . $cleaned;
        }
        list($min, $max) = explode(':', getConfig('twilio_phone_range'));

        return strlen($cleaned) >= $min && strlen($cleaned) <= $max ? '+' . getConfig('twilio_country_code') . $cleaned : false;
    }

    private function htmlToText($content)
    {
        return trim(HTML2Text($content));
    }

    private function isSMSCampaign($messageData)
    {
        return isset($messageData['sendformat']) && $messageData['sendformat'] == 'sms';
    }

    private function cacheCampaign($messageData)
    {
        $campaign = [];
        $campaign['isSMS'] = $this->isSMSCampaign($messageData);

        if ($campaign['isSMS']) {
            $campaign['smsBody'] = $this->htmlToText($messageData['message']);
            $campaign['smsFrom'] = $messageData['twilio_from'];
        }
        $mid = $messageData['id'];
        $this->campaigns[$mid] = $campaign;
    }

    private function updatePhoneAttribute($email, $phone, $phoneAttr)
    {
        global $tables;

        $sql = <<<END
    UPDATE {$tables['user_attribute']} ua
    JOIN  {$tables['user']} u ON u.id = ua.userid
    SET ua.value = '$phone'
    WHERE u.email = '$email' AND ua.attributeid = $phoneAttr
END;
        Sql_Query($sql);
    }

    public function __construct()
    {
        $this->coderoot = dirname(__FILE__) . '/' . self::PLUGIN . '/';
        $this->version = (is_file($f = $this->coderoot . self::VERSION_FILE))
            ? file_get_contents($f)
            : '2017-02-21';
        parent::__construct();
    }

    /**
     * Provide the dependencies for enabling this plugin.
     *
     * @return array
     */
    public function dependencyCheck()
    {
        return [
            'PHP version 5.4.0 or greater' => version_compare(PHP_VERSION, '5.4') > 0,
            'curl extension installed' => extension_loaded('curl'),
            'Common Plugin installed' => phpListPlugin::isEnabled('CommonPlugin'),
            'phpList 3.3.0 or greater' => version_compare(VERSION, '3.3') > 0,
        ];
    }

    public function shutdown()
    {
        logEvent(sprintf('Twilio successes: %d, failures: %d', $this->sendSuccess, $this->sendFail));
    }

    public function sendFormats()
    {
        global $plugins;

        require_once $plugins['CommonPlugin']->coderoot . 'Autoloader.php';

        $this->logger = \phpList\plugin\Common\Logger::instance();

        return array('sms' => 'SMS');
    }

    public function sendMessageTab($messageid = 0, $data = array())
    {
        if (!$this->isSMSCampaign($data)) {
            return '';
        }

        if (isset($data['message'])) {
            $dataLength = strlen($this->htmlToText($data['message']));
            $parts = $dataLength <= 160 ? 1 : (int) (($dataLength - 1) / 157) + 1;
        } else {
            $dataLength = 0;
            $parts = 1;
        }
        $parts .= $parts == 1 ? ' segment' : ' segments';
        $from = empty($data['twilio_from']) ? getConfig('twilio_default_from') : htmlspecialchars($data['twilio_from']);
        $html = <<<END
    <label>The From number
    <input type="text" name="twilio_from" value="$from" /></label>
    <p>The length of the SMS message is $dataLength characters.
    <br/> The SMS message will be sent as $parts.</p>
END;

        return $html;
    }

    public function sendMessageTabTitle($messageid = 0)
    {
        return 'SMS';
    }

    public function sendMessageTabInsertBefore()
    {
        return 'Attach';
    }

    /**
     * Validate the SMS fields of the campaign.
     *
     * @param array $messageData message fields
     *
     * @return string an empty string for success otherwise the error reasons
     */
    public function allowMessageToBeQueued($messageData = array())
    {
        if (!$this->isSMSCampaign($messageData)) {
            return '';
        }
        $error = array();

        if (!isset($messageData['twilio_from'])) {
            $error[] = 'From number must be entered';
        } elseif (!preg_match('/^\+\d+$/', $messageData['twilio_from'])) {
            $error[] = "Invalid from number {$messageData['twilio_from']} ";
        }

        if (strlen($this->htmlToText($messageData['message'])) > 1600) {
            $error[] = 'The SMS text is longer than 1600 characters';
        }

        return implode('<br />', $error);
    }

    /**
     * Use this hook to store the SMS fields of the campaign.
     *
     * @param array $messageData message fields
     *
     * @return bool true
     */
    public function sendTestAllowed($messageData)
    {
        $this->cacheCampaign($messageData);

        return true;
    }

    /**
     * Use this hook to store the SMS fields of the campaign.
     *
     * @param array $messageData message fields
     */
    public function campaignStarted($messageData = array())
    {
        $this->cacheCampaign($messageData);
    }

    /**
     * Determine whether the campaign should be sent to a particular subscriber.
     * For an SMS campaign the subscriber must have chosen to receive SMS texts.
     *
     * @param array $messagedata    message fields
     * @param array $subscriberdata subscriber fields
     *
     * @return bool send / do not send
     */
    public function canSend($messagedata, $subscriberdata)
    {
        if (!$this->isSMSCampaign($messagedata)) {
            return true;
        }
        $attrValues = getUserAttributeValues($subscriberdata['email'], 0, true);
        $prefer = getConfig('twilio_prefer_attribute');

        if (!(isset($attrValues['attribute' . $prefer]) && $attrValues['attribute' . $prefer])) {
            return false;
        }
        $phoneAttr = getConfig('twilio_phone_attribute');
        $phone = $this->transformPhoneNumber($attrValues['attribute' . $phoneAttr]);

        if (!$phone) {
            return false;
        }
        $this->currentSubscriber = [
            'phone' => $phone,
            'email' => $subscriberdata['email'],
        ];

        return true;
    }

    /**
     * Send a campaign message as an SMS text through Twilio.
     * Admin messages and non-SMS campaigns are sent through Amazon SES.
     *
     * @see
     *
     * @param PHPlistMailer $mailer mailer instance
     * @param string        $header the message http headers
     * @param string        $body   the message body
     *
     * @return bool success/failure
     */
    public function send(PHPlistMailer $mailer, $header, $body)
    {
        static $client = null;

        if (!preg_match('/X-MessageID: (\d+)/', $header, $matches)) {
            return $this->sendNonSms($mailer, $header, $body);
        }
        $mid = $matches[1];
        $campaign = $this->campaigns[$mid];

        if (!$campaign['isSMS']) {
            return $this->sendNonSms($mailer, $header, $body);
        }
        preg_match('/X-ListMember: (.+)/', $header, $matches);
        $email = $matches[1];

        if ($this->currentSubscriber && $email == $this->currentSubscriber['email']) {
            $phone = $this->currentSubscriber['phone'];
        } else {
            $this->logger->debug('Twilio did not find subscriber');
            $attrValues = getUserAttributeValues($email, 0, true);
            $phoneAttr = getConfig('twilio_phone_attribute');
            $attrValue = $attrValues['attribute' . $phoneAttr];
            $phone = $this->transformPhoneNumber($attrValue);

            if (!$phone) {
                logEvent(sprintf('Twilio - problem with To number: %s', $attrValue));

                return false;
            }
        }

        try {
            if (is_null($client)) {
                require 'Twilio/Twilio/autoload.php';

                $client = new Twilio\Rest\Client(getConfig('twilio_sid'), getConfig('twilio_auth_token'));
                register_shutdown_function([$this, 'shutdown']);
            }
            $message = $client->messages->create(
                $phone,
                [
                    'from' => $campaign['smsFrom'],
                    'body' => $campaign['smsBody'],
                ]
            );
        } catch (\Exception $e) {
            $code = $e->getCode();

            if (!in_array($code, ['21212', '21606', '21611'])) {
                $this->updatePhoneAttribute($email, '!' . $phone, $phoneAttr);
            }
            logEvent(sprintf('Twilio - exception: %s %s', $code, $e->getMessage()));
            ++$this->sendFail;

            return false;
        }
        ++$this->sendSuccess;
        $this->logger->debug('Twilio return code: ' . $message->sid);

        return true;
    }

    /**
     * This hook is called within the processqueue shutdown() function.
     *
     * phplist exits in its shutdown function therefore need to explicitly call our plugin
     */
    public function processSendStats($sent = 0, $invalid = 0, $failed_sent = 0, $unconfirmed = 0, $counters = array())
    {
        if ($this->sendSuccess > 0 || $this->sendFail > 0) {
            $this->shutdown();
        }
    }
}
