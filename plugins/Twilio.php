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
use phpList\plugin\Common\Logger;
use phpList\plugin\Twilio\DAO;

use function phpList\plugin\Common\publicUrl;

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
    private $amazonSesPlugin;
    private $dao;
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
        return defined('TWILIO_DEV') && TWILIO_DEV
            ? $mailer->localSpoolSend($header, $body)
            : $this->amazonSesPlugin->send($mailer, $header, $body);
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
            $campaign['smsFrom'] = $messageData['twilio_from'];
        }
        $mid = $messageData['id'];
        $this->campaigns[$mid] = $campaign;
    }

    private function attachments($mid, $uid)
    {
        $mediaUrls = [];

        foreach ($this->dao->messageAttachments($mid) as $row) {
            $mediaUrls[] = publicUrl('dl.php', ['id' => $row['id'], 'uid' => $uid]);
        }

        return $mediaUrls;
    }

    private function showAttachments($messageid)
    {
        $attachSize = 0;
        $attachText = '';
        $attachments = $this->dao->messageAttachments($messageid);

        if (count($attachments) > 0) {
            $attachText .= '<label>Attachments</label>';

            foreach ($attachments as $row) {
                $remotefile = htmlspecialchars($row['remotefile']);
                $size = $row['size'];
                $attachSize += $size;
                $attachText .= "$remotefile $size bytes<br/>";
            }
        }

        return [$attachSize, $attachText];
    }

    public function __construct()
    {
        $this->coderoot = dirname(__FILE__) . '/' . self::PLUGIN . '/';
        parent::__construct();
        $this->version = (is_file($f = $this->coderoot . self::VERSION_FILE))
            ? file_get_contents($f)
            : '2017-02-21';
    }

    public function activate()
    {
        global $allplugins;

        $this->amazonSesPlugin = $allplugins['AmazonSes'];
        $this->settings += $this->amazonSesPlugin->settings;

        require_once $this->amazonSesPlugin->coderoot . '/MailClient.php';

        parent::activate();
        $this->dao = new DAO();
        $this->logger = Logger::instance();
    }

    /**
     * Provide the dependencies for enabling this plugin.
     *
     * @return array
     */
    public function dependencyCheck()
    {
        global $allplugins, $plugins;

        return [
            'curl extension installed' => extension_loaded('curl'),
            'Common Plugin v3.29.0 or later installed' => (
                phpListPlugin::isEnabled('CommonPlugin')
                    && version_compare($plugins['CommonPlugin']->version, '3.29.0') >= 0
            ),
            'phpList 3.3.0 or greater' => version_compare(VERSION, '3.3') > 0,
            'Amazon SES plugin installed but not enabled' => isset($allplugins['AmazonSes']) && !isset($plugins['AmazonSes']),
        ];
    }

    public function shutdown()
    {
        logEvent(sprintf('Twilio successes: %d, failures: %d', $this->sendSuccess, $this->sendFail));
    }

    public function sendFormats()
    {
        return array('sms' => 'SMS');
    }

    public function sendMessageTab($messageid = 0, $data = array())
    {
        if (!$this->isSMSCampaign($data)) {
            return '';
        }
        list($attachSize, $attachText) = $this->showAttachments($messageid);

        if (isset($data['message'])) {
            $dataLength = strlen($this->htmlToText($data['message'])) + $attachSize;
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
    <p>The length of the SMS message including any attachments is $dataLength characters.
    <br/> The SMS message will be sent as $parts.</p>
    $attachText
END;

        return $html;
    }

    public function sendMessageTabTitle($messageid = 0)
    {
        return 'SMS';
    }

    public function sendMessageTabInsertBefore()
    {
        return 'Scheduling';
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

        if ($messageData['footer'] != '') {
            $error[] = 'The campaign footer must be empty for an SMS message';
        }

        if ($messageData['template'] != 0) {
            $error[] = 'A template cannot be used for an SMS message';
        }

        return implode('<br />', $error);
    }

    /**
     * Provide a read-only view of the SMS fields.
     *
     * @param int   $messageId   message id
     * @param array $messageData message fields
     */
    public function viewMessage($messageId, array $messageData)
    {
        if (!$this->isSMSCampaign($messageData)) {
            return false;
        }
        $tabHtml = $this->sendMessageTab($messageId, $messageData);
        $html = <<<END
    <fieldset disabled>
    $tabHtml
    </fieldset>
END;

        return ['SMS', $html];
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
     * For an SMS campaign the subscriber must have chosen to receive SMS texts and have
     * a valid phone number.
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
        $prefer = UserAttributeValue($subscriberdata['id'], getConfig('twilio_prefer_attribute'));

        if (!$prefer) {
            return false;
        }
        $phoneAttrValue = UserAttributeValue($subscriberdata['id'], getConfig('twilio_phone_attribute'));
        $phone = $this->transformPhoneNumber($phoneAttrValue);

        if (!$phone) {
            return false;
        }
        $this->currentSubscriber = [
            'phone' => $phone,
            'email' => $subscriberdata['email'],
            'uid' => $subscriberdata['uniqid'],
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
            $uid = $this->currentSubscriber['uid'];
            $phone = $this->currentSubscriber['phone'];
        } else {
            // this path is for when a test email is sent
            $this->logger->debug('Twilio did not find subscriber');
            $user = $this->dao->userByEmail($email);
            $uid = $user['uniqid'];
            $phoneAttrValue = UserAttributeValue($user['id'], getConfig('twilio_phone_attribute'));
            $phone = $this->transformPhoneNumber($phoneAttrValue);

            if (!$phone) {
                logEvent(sprintf('Twilio - Not sending to: %s', $phoneAttrValue));

                return false;
            }
        }
        // use the text format generated by phplist, remove credits
        $body = $mailer->AltBody == '' ? $mailer->Body : $mailer->AltBody;
        $credits = '-- powered by phpList, www.phplist.com --';

        if (false !== ($pos = strpos($body, $credits))) {
            $body = trim(substr($body, 0, $pos));
        }
        $parameters = [
            'from' => $campaign['smsFrom'] ?? getConfig('twilio_default_from'),
            'body' => $body,
        ];
        $this->logger->debug('Parameters', $parameters);

        if ($mediaUrls = $this->attachments($mid, $uid)) {
            $parameters['mediaUrl'] = $mediaUrls;
            $this->logger->debug('Attachments', $mediaUrls);
        }

        try {
            if (is_null($client)) {
                require 'Twilio/Twilio/autoload.php';

                $client = new Twilio\Rest\Client(getConfig('twilio_sid'), getConfig('twilio_auth_token'));
                register_shutdown_function([$this, 'shutdown']);
            }
            $message = $client->messages->create($phone, $parameters);
        } catch (\Exception $e) {
            $code = $e->getCode();

            if (!in_array($code, ['21212', '21606', '21611', '21617', '21620', '21621'])) {
                $this->dao->updateUserAttribute($email, getConfig('twilio_phone_attribute'), '!' . $phone);
            }
            logEvent(sprintf('Twilio - exception: %s %s', $code, $e->getMessage()));
            ++$this->sendFail;

            return false;
        }
        ++$this->sendSuccess;
        $this->logger->debug('Response', [$message]);

        return true;
    }

    /**
     * This hook is called within the processqueue shutdown() function.
     *
     * phplist exits in its shutdown function therefore need to explicitly call our plugin
     */
    public function processSendStats($sent = 0, $invalid = 0, $failed_sent = 0, $unconfirmed = 0, $counters = array())
    {
        $this->amazonSesPlugin->processSendStats($sent, $invalid, $failed_sent, $unconfirmed, $counters);

        if ($this->sendSuccess > 0 || $this->sendFail > 0) {
            $this->shutdown();
        }
    }
}
