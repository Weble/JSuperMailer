<?php
/**
 * @author     Weble Srl
 *
 * @copyright  Copyright (C) 2016 weble.it . All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 *
 * This is a modified version of the JMailer Class that works with any SwiftMailer Transport
 * Heavily based on  Daniel Dimitrov's CMandrill plugin
 */

/**
 * @author     Daniel Dimitrov <daniel@compojoom.com>
 * @date       22.02.13
 *
 * @copyright  Copyright (C) 2008 - 2013 compojoom.com . All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 *
 * This is a modified version of the JMailer Class that works with
 * the Mandrill API
 */

/**
 * @version        $Id: mail.php 14401 2010-01-26 14:10:00Z louis $
 * @package        Joomla.Framework
 * @subpackage     Mail
 * @copyright      Copyright (C) 2005 - 2010 Open Source Matters. All rights reserved.
 * @license        GNU/GPL, see LICENSE.php
 * Joomla! is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See COPYRIGHT.php for copyright notices and details.
 */
// Check to ensure this file is within the rest of the framework
defined('JPATH_BASE') or die();

jimport('phpmailer.phpmailer');
jimport('joomla.mail.helper');

use Joomla\Registry\Registry;
use Aws\Ses\SesClient;
use GuzzleHttp\Client as HttpClient;
use Swift_SmtpTransport as SmtpTransport;
use Swift_MailTransport as MailTransport;
use Webleit\JSuperMailer\Transport\MailgunTransport;
use Webleit\JSuperMailer\Transport\MandrillTransport;
use Webleit\JSuperMailer\Transport\SesTransport;
use Swift_SendmailTransport as SendmailTransport;

/**
 * Email Class.  Provides a common interface to send email from the Joomla! Platform
 *
 * @package     Joomla.Platform
 * @subpackage  Mail
 * @since       11.1
 */
class JMail extends PHPMailer
{
	/**
	 * @var    array  JMail instances container.
	 * @since  11.3
	 */
	protected static $instances = array();

	/**
	 * @var    string  Charset of the message.
	 * @since  11.1
	 */
	public $CharSet = 'utf-8';

	/**
	 * @var Swift_Mailer Mailer
	 */
	protected $swift = null;

	/**
	 * @var \JRegistry $supermailerParams
	 */
	protected $supermailerParams = null;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		// Phpmailer has an issue using the relative path for it's language files
		$this->SetLanguage('joomla', JPATH_PLATFORM . '/joomla/mail/language/');

		// Load the admin language
		$language = JFactory::getLanguage();
		$language->load('plg_system_jsupermailer.sys', JPATH_ADMINISTRATOR, 'en-GB', true);
		$language->load('plg_system_jsupermailer.sys', JPATH_ADMINISTRATOR, $language->getDefault(), true);
		$language->load('plg_system_jsupermailer.sys', JPATH_ADMINISTRATOR, null, true);

		// Initialize the logger class
		jimport('joomla.error.log');
		$date = JFactory::getDate()->format('Y_m');

		// Add the logger.
		JLog::addLogger(
			array(
				'text_file' => 'plg_system_jsupermailer.log.' . $date . '.php'
			)
		);

		$this->supermailerParams = new Registry(\JPluginHelper::getPlugin('system', 'jsupermailer')->params);
		$this->swift = new Swift_Mailer($this->getTransport());
	}

	/**
	 * @return Transport
	 */
	protected function getTransport()
	{
		$type = $this->supermailerParams->get('transport', 'mail');
		$method = 'create' . ucfirst(strtolower($type)) . 'Driver';

		return $this->$method();
	}

	/**
	 * Returns the global email object, only creating it
	 * if it doesn't already exist.
	 *
	 * NOTE: If you need an instance to use that does not have the global configuration
	 * values, use an id string that is not 'Joomla'.
	 *
	 * @param   string  $id  The id string for the JMail instance [optional]
	 *
	 * @return  JMail  The global JMail object
	 *
	 * @since   11.1
	 */
	public static function getInstance($id = 'Joomla')
	{
		if (empty(self::$instances[$id]))
		{
			self::$instances[$id] = new JMail;
		}

		return self::$instances[$id];
	}

	/**
	 * Sends the email -> either trough PHPMailer or through Mandrill
	 *
	 * @return mixed True if successful, a JError object otherwise
	 */
	public function Send()
	{
		if (JFactory::getConfig()->get('mailonline', 1))
		{
			$message = $this->getMessage();
			return $this->swift->send($message);
		}
		else
		{
			JFactory::getApplication()->enqueueMessage(JText::_('JLIB_MAIL_FUNCTION_OFFLINE'));
			return false;
		}
	}

	/**
	 * @return Swift_Message
	 */
	public function getMessage()
	{
		$message = Swift_Message::newInstance()
			->setCharset($this->CharSet)
			->setSubject($this->Subject)
			->setFrom(array($this->From => $this->FromName));

		// Normal Addresses
		foreach (array('to', 'bcc', 'cc') as $type) {
			$tos = array();



			foreach ($this->$type as $to) {
				$to = array_filter($to);

				if (count($to) > 1) {
					$tos[$to[0]] = $to[1];
				} else {
					$tos[] = $to[0];
				}
			}

			$method = 'set' . ucfirst($type);
			$message->$method($tos);
		}

		// Reply to addresses
		$tos = array();
		foreach ($this->ReplyTo as $to) {
			$to = array_filter($to);
			if (count($to) > 1) {
				$tos[$to[0]] = $to[1];
			} else {
				$tos[] = $to[0];
			}
		}

		// Reply to
		$message->setReplyTo($tos);

		// Body
		$message->setBody($this->Body, $this->ContentType);

		if ($this->isHTML()) {
			$message->addPart($this->AltBody, 'text/plain');
		}

		// Attachments
		foreach($this->getAttachments() as $attachment) {
			/*
			array(
				0 => $path,
				1 => $filename,
				2 => $name,
				3 => $encoding,
				4 => $type,
				5 => false, // isStringAttachment
				6 => $disposition,
				7 => 0
			);*/
			$att = Swift_Attachment::fromPath($attachment[0])->setFilename($attachment[1])->setDisposition($attachment[6]);
			$message->attach($att);
		}

		return $message;
	}

	/**
	 * Set the email sender
	 *
	 * @param   array  $from  email address and Name of sender
	 *                        <code>array([0] => email Address [1] => Name)</code>
	 *
	 * @return  JMail  Returns this object for chaining.
	 *
	 * @since   11.1
	 */
	public function setSender($from)
	{
		if (is_array($from))
		{
			// If $from is an array we assume it has an address and a name
			if (isset($from[2]))
			{
				// If it is an array with entries, use them
				$this->setFrom(JMailHelper::cleanLine($from[0]), JMailHelper::cleanLine($from[1]), (bool) $from[2]);
			}
			else
			{
				$this->setFrom(JMailHelper::cleanLine($from[0]), JMailHelper::cleanLine($from[1]));
			}
		}
		elseif (is_string($from))
		{
			// If it is a string we assume it is just the address
			$this->setFrom(JMailHelper::cleanLine($from));
		}
		else
		{
			// If it is neither, we log a message and throw an exception
			JLog::add(JText::sprintf('JLIB_MAIL_INVALID_EMAIL_SENDER', $from), JLog::WARNING, 'jerror');

			throw new UnexpectedValueException(sprintf('Invalid email Sender: %s, JMail::setSender(%s)', $from));
		}

		return $this;
	}

	/**
	 * Set the email subject
	 *
	 * @param   string  $subject  Subject of the email
	 *
	 * @return  JMail  Returns this object for chaining.
	 *
	 * @since   11.1
	 */
	public function setSubject($subject)
	{
		$this->Subject = JMailHelper::cleanLine($subject);

		return $this;
	}

	/**
	 * Set the email body
	 *
	 * @param   string  $content  Body of the email
	 *
	 * @return  JMail  Returns this object for chaining.
	 *
	 * @since   11.1
	 */
	public function setBody($content)
	{
		/*
		 * Filter the Body
		 * TODO: Check for XSS
		 */
		$this->Body = JMailHelper::cleanText($content);

		return $this;
	}

	/**
	 * Add recipients to the email.
	 *
	 * @param   mixed   $recipient  Either a string or array of strings [email address(es)]
	 * @param   mixed   $name       Either a string or array of strings [name(s)]
	 * @param   string  $method     The parent method's name.
	 *
	 * @return  JMail  Returns this object for chaining.
	 *
	 * @since   11.1
	 * @throws  InvalidArgumentException
	 */
	protected function add($recipient, $name = '', $method = 'addAddress')
	{
		$method = lcfirst($method);

		// If the recipient is an array, add each recipient... otherwise just add the one
		if (is_array($recipient))
		{
			if (is_array($name))
			{
				$combined = array_combine($recipient, $name);

				if ($combined === false)
				{
					throw new InvalidArgumentException("The number of elements for each array isn't equal.");
				}

				foreach ($combined as $recipientEmail => $recipientName)
				{
					$recipientEmail = JMailHelper::cleanLine($recipientEmail);
					$recipientName = JMailHelper::cleanLine($recipientName);
					call_user_func('parent::' . $method, $recipientEmail, $recipientName);
				}
			}
			else
			{
				$name = JMailHelper::cleanLine($name);

				foreach ($recipient as $to)
				{
					$to = JMailHelper::cleanLine($to);
					call_user_func('parent::' . $method, $to, $name);
				}
			}
		}
		else
		{
			$recipient = JMailHelper::cleanLine($recipient);
			call_user_func('parent::' . $method, $recipient, $name);
		}

		return $this;
	}

	/**
	 * Add recipients to the email
	 *
	 * @param   mixed  $recipient  Either a string or array of strings [email address(es)]
	 * @param   mixed  $name       Either a string or array of strings [name(s)]
	 *
	 * @return  JMail  Returns this object for chaining.
	 *
	 * @since   11.1
	 */
	public function addRecipient($recipient, $name = '')
	{
		$this->add($recipient, $name, 'addAddress');

		return $this;
	}

	/**
	 * Add carbon copy recipients to the email
	 *
	 * @param   mixed  $cc    Either a string or array of strings [email address(es)]
	 * @param   mixed  $name  Either a string or array of strings [name(s)]
	 *
	 * @return  JMail  Returns this object for chaining.
	 *
	 * @since   11.1
	 */
	public function addCC($cc, $name = '')
	{
		// If the carbon copy recipient is an array, add each recipient... otherwise just add the one
		if (isset($cc))
		{
			$this->add($cc, $name, 'addCC');
		}

		return $this;
	}

	/**
	 * Add blind carbon copy recipients to the email
	 *
	 * @param   mixed  $bcc   Either a string or array of strings [email address(es)]
	 * @param   mixed  $name  Either a string or array of strings [name(s)]
	 *
	 * @return  JMail  Returns this object for chaining.
	 *
	 * @since   11.1
	 */
	public function addBCC($bcc, $name = '')
	{
		// If the blind carbon copy recipient is an array, add each recipient... otherwise just add the one
		if (isset($bcc))
		{
			$this->add($bcc, $name, 'addBCC');
		}

		return $this;
	}

	/**
	 * Add file attachment to the email
	 *
	 * @param   mixed   $path         Either a string or array of strings [filenames]
	 * @param   mixed   $name         Either a string or array of strings [names]
	 * @param   mixed   $encoding     The encoding of the attachment
	 * @param   mixed   $type         The mime type
	 * @param   string  $disposition  The disposition of the attachment
	 *
	 * @return  JMail  Returns this object for chaining.
	 *
	 * @since   12.2
	 * @throws  InvalidArgumentException
	 */
	public function addAttachment($path, $name = '', $encoding = 'base64', $type = 'application/octet-stream', $disposition = 'attachment')
	{
		// If the file attachments is an array, add each file... otherwise just add the one
		if (isset($path))
		{
			if (is_array($path))
			{
				if (!empty($name) && count($path) != count($name))
				{
					throw new InvalidArgumentException("The number of attachments must be equal with the number of name");
				}

				foreach ($path as $key => $file)
				{
					if (!empty($name))
					{
						parent::addAttachment($file, $name[$key], $encoding, $type);
					}
					else
					{
						parent::addAttachment($file, $name, $encoding, $type);
					}
				}
			}
			else
			{
				parent::addAttachment($path, $name, $encoding, $type);
			}
		}

		return $this;
	}

	/**
	 * Add Reply to email address(es) to the email
	 *
	 * @param   array         $replyto  Either an array or multi-array of form
	 *                                  <code>array([0] => email Address [1] => Name)</code>
	 * @param   array|string  $name     Either an array or single string
	 *
	 * @return  JMail  Returns this object for chaining.
	 *
	 * @since   11.1
	 */
	public function addReplyTo($replyto, $name = '')
	{
		$this->add($replyto, $name, 'addReplyTo');

		return $this;
	}

	/**
	 * Use sendmail for sending the email
	 *
	 * @param   string  $sendmail  Path to sendmail [optional]
	 *
	 * @return  boolean  True on success
	 *
	 * @since   11.1
	 */
	public function useSendmail($sendmail = null)
	{
		$this->Sendmail = $sendmail;

		if (!empty($this->Sendmail))
		{
			$this->IsSendmail();

			return true;
		}
		else
		{
			$this->IsMail();

			return false;
		}
	}

	/**
	 * Use SMTP for sending the email
	 *
	 * @param   string   $auth    SMTP Authentication [optional]
	 * @param   string   $host    SMTP Host [optional]
	 * @param   string   $user    SMTP Username [optional]
	 * @param   string   $pass    SMTP Password [optional]
	 * @param   string   $secure  Use secure methods
	 * @param   integer  $port    The SMTP port
	 *
	 * @return  boolean  True on success
	 *
	 * @since   11.1
	 */
	public function useSMTP($auth = null, $host = null, $user = null, $pass = null, $secure = null, $port = 25)
	{
		$this->SMTPAuth = $auth;
		$this->Host = $host;
		$this->Username = $user;
		$this->Password = $pass;
		$this->Port = $port;

		if ($secure == 'ssl' || $secure == 'tls')
		{
			$this->SMTPSecure = $secure;
		}

		if (($this->SMTPAuth !== null && $this->Host !== null && $this->Username !== null && $this->Password !== null)
			|| ($this->SMTPAuth === null && $this->Host !== null))
		{
			$this->IsSMTP();

			return true;
		}
		else
		{
			$this->IsMail();

			return false;
		}
	}

	/**
	 * Function to send an email
	 *
	 * @param   string    $from         From email address
	 * @param   string    $fromName     From name
	 * @param   mixed     $recipient    Recipient email address(es)
	 * @param   string    $subject      email subject
	 * @param   string    $body         Message body
	 * @param   bool|int  $mode         false = plain text, true = HTML
	 * @param   mixed     $cc           CC email address(es)
	 * @param   mixed     $bcc          BCC email address(es)
	 * @param   mixed     $attachment   Attachment file name(s)
	 * @param   mixed     $replyTo      Reply to email address(es)
	 * @param   mixed     $replyToName  Reply to name(s)
	 *
	 * @return  boolean  True on success
	 *
	 * @since   11.1
	 */
	public function sendMail($from, $fromName, $recipient, $subject, $body, $mode = false, $cc = null, $bcc = null, $attachment = null,
		$replyTo = null, $replyToName = null)
	{
		$this->setSubject($subject);
		$this->setBody($body);

		// Are we sending the email as HTML?
		if ($mode)
		{
			$this->IsHTML(true);
		}

		$this->addRecipient($recipient);
		$this->addCC($cc);
		$this->addBCC($bcc);
		$this->addAttachment($attachment);

		// Take care of reply email addresses
		if (is_array($replyTo))
		{
			$numReplyTo = count($replyTo);

			for ($i = 0; $i < $numReplyTo; $i++)
			{
				$this->addReplyTo($replyTo[$i], $replyToName[$i]);
			}
		}
		elseif (isset($replyTo))
		{
			$this->addReplyTo($replyTo, $replyToName);
		}

		// Add sender to replyTo only if no replyTo received
		$autoReplyTo = (empty($this->ReplyTo)) ? true : false;
		$this->setSender(array($from, $fromName, $autoReplyTo));

		return $this->Send();
	}

	/**
	 * Sends mail to administrator for approval of a user submission
	 *
	 * @param   string  $adminName   Name of administrator
	 * @param   string  $adminEmail  Email address of administrator
	 * @param   string  $email       [NOT USED TODO: Deprecate?]
	 * @param   string  $type        Type of item to approve
	 * @param   string  $title       Title of item to approve
	 * @param   string  $author      Author of item to approve
	 * @param   string  $url         A URL to included in the mail
	 *
	 * @return  boolean  True on success
	 *
	 * @since   11.1
	 */
	public function sendAdminMail($adminName, $adminEmail, $email, $type, $title, $author, $url = null)
	{
		$subject = JText::sprintf('JLIB_MAIL_USER_SUBMITTED', $type);

		$message = sprintf(JText::_('JLIB_MAIL_MSG_ADMIN'), $adminName, $type, $title, $author, $url, $url, 'administrator', $type);
		$message .= JText::_('JLIB_MAIL_MSG') . "\n";

		$this->addRecipient($adminEmail);
		$this->setSubject($subject);
		$this->setBody($message);

		return $this->Send();
	}

	/**
	 * Writes to the log
	 *
	 * @param   string  $message  - the message
	 *
	 * @return void
	 */
	private function writeToLog($message)
	{
		JLog::add($message, JLog::WARNING);
	}

	/**
	 * Create an instance of the SMTP Swift Transport driver.
	 *
	 * @return \Swift_SmtpTransport
	 */
	protected function createSmtpDriver()
	{
		// The Swift SMTP transport instance will allow us to use any SMTP backend
		// for delivering mail such as Sendgrid, Amazon SES, or a custom server
		// a developer has available. We will just pass this configured host.
		$transport = SmtpTransport::newInstance(
			$this->Host, $this->Port
		);

		if ($this->SMTPAuth) {
			$transport->setEncryption($this->SMTPAuth);
		}

		// Once we have the transport we will check for the presence of a username
		// and password. If we have it we will set the credentials on the Swift
		// transporter instance so that we'll properly authenticate delivery.
		if ($this->Username) {
			$transport->setUsername($this->Username);
			$transport->setPassword($this->Password);
		}

		return $transport;
	}

	/**
	 * Create an instance of the Sendmail Swift Transport driver.
	 *
	 * @return \Swift_SendmailTransport
	 */
	protected function createSendmailDriver()
	{
		return SendmailTransport::newInstance($this->Sendmail);
	}

	/**
	 * Create an instance of the Amazon SES Swift Transport driver.
	 *
	 * @return \Swift_SendmailTransport
	 */
	protected function createSesDriver()
	{
		$config = array(
			'credentials' => array(
				'key' => $this->supermailerParams->get('ses_key', ''),
				'secret' => $this->supermailerParams->get('ses_secret', '')
			),
			'region' => $this->supermailerParams->get('ses_region', ''),
			'version' => 'latest',
			'service' => 'email'
		);

		return new SesTransport(new SesClient($config));
	}

	/**
	 * Create an instance of the Mail Swift Transport driver.
	 *
	 * @return \Swift_MailTransport
	 */
	protected function createMailDriver()
	{
		return MailTransport::newInstance();
	}

	/**
	 * Create an instance of the Mailgun Swift Transport driver.
	 *
	 * @return \Webleit\JSuperMailer\Transport\MailgunTransport
	 */
	protected function createMailgunDriver()
	{
		$client = new HttpClient([]);
		return new MailgunTransport($client, $this->supermailerParams->get('mailgun_key', ''), $this->supermailerParams->get('mailgun_domain', ''));
	}

	/**
	 * Create an instance of the Mandrill Swift Transport driver.
	 *
	 * @return \Webleit\JSuperMailer\Transport\MandrillTransport
	 */
	protected function createMandrillDriver()
	{
		$client = new HttpClient([]);

		return new MandrillTransport($client, $this->supermailerParams->get('mandrill_key', ''));
	}
}
