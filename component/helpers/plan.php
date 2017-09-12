<?php

/**
 * @package        Joomla
 * @subpackage     Membership Pro
 * @author         Tuan Pham Ngoc
 * @copyright      Copyright (C) 2012 - 2017 Ossolution Team
 * @license        GNU/GPL, see LICENSE.php
 */
class HelperOSappscheduleSubscription
{
	/**
	 * Get membership profile record of the given user
	 *
	 * @param int $userId
	 *
	 * @return object
	 */
	public static function getMembershipProfile($userId)
	{
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->select('a.*, b.username')
			->from('#__osmembership_subscribers AS a ')
			->leftJoin('#__users AS b ON a.user_id = b.id')
			->where('is_profile = 1')
			->where('user_id = ' . (int) $userId)
			->order('a.id DESC');
		$db->setQuery($query);

		return $db->loadObject();
	}

	/**
	 * Try to fix ProfileID for user if it was lost for some reasons - for example, admin delete
	 *
	 * @param $userId
	 *
	 * @return bool
	 */
	public static function fixProfileId($userId)
	{
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);

		$userId = (int) $userId;
		$query->select('id')
			->from('#__osmembership_subscribers')
			->where('user_id = ' . $userId)
			->order('id DESC');
		$db->setQuery($query);
		$id = (int) $db->loadResult();

		if ($id)
		{
			// Make this record as profile ID
			$query->clear()
				->update('#__osmembership_subscribers')
				->set('is_profile = 1')
				->set('profile_id =' . $id)
				->where('id = ' . $id);
			$db->setQuery($query);
			$db->execute();

			// Mark all other records of this user has profile_id = ID of this record
			$query->clear()
				->update('#__osmembership_subscribers')
				->set('profile_id = ' . $id)
				->where('user_id = ' . $userId)
				->where('id != ' . $id);
			$db->setQuery($query);
			$db->execute();

			return true;
		}

		return false;
	}

	/**
	 * Get active subscription plan ids of the given user
	 *
	 * @param int   $userId
	 * @param array $excludes
	 *
	 * @return array
	 */
	public static function getActiveMembershipPlans($userId = 0)
	{
		$activePlans = array(0);

		if (!$userId)
		{
			$userId = (int) JFactory::getUser()->get('id');
		}

		if ($userId > 0)
		{
			$db    = JFactory::getDbo();
			$query = $db->getQuery(true);
			$now   = $db->quote(JFactory::getDate('now')->format('Y-m-d'));
			$query->select('b.*,a.subscription_quotas,a.lifetime_membership, b.id as active_subscription_id, a.remainder_quotas')
				->from('#__osmembership_plans AS a')
				->innerJoin('#__osmembership_subscribers AS b ON a.id = b.plan_id')
				->where('b.user_id = ' . $userId)
				->where('a.published = 1')
				->where('(b.plan_subscription_status <= 1 OR b.published <= 1 and (a.lifetime_membership = 1 OR (DATEDIFF(' . $now . ', from_date) >= -1 AND DATE(to_date) >= ' . $now .')))')
				->order('b.created_date ASC');

			$db->setQuery($query);

			$activePlans = $db->loadObjectList();
		}

		return $activePlans;
	}

	/**
	 * Set a susbcription as expired
	 *
	 * @param int   $subscription_id
	 *
	 * @return bool
	 */
	public static function setExpiredMembershipPlans($subscription_id = 0){
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);

		$now = JFactory::getDate('now')->toSql();

		$query->clear()
						->update('#__osmembership_subscribers')
						->set('plan_subscription_status = 2')
						->set('published = 2')
						->set('updated_date = \''.$now.'\'')
						->set('act = \'expired\'')
						->where('id = ' . $subscription_id);
					$db->setQuery($query);
					$db->execute();
	}

	/**
	 * Set a susbcription as consummed
	 *
	 * @param int   $subscription_id
	 *
	 * @return bool
	 */
	public static function setConsummedMembershipPlans($subscription_id = 0){
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);

		$now = JFactory::getDate('now')->toSql();

		$query->clear()
						->update('#__osmembership_subscribers')
						->set('plan_subscription_status = 5')
						->set('published = 5')
						->set('updated_date = \''.$now.'\'')
						->set('act = \'consumed\'')
						->where('id = ' . $subscription_id);
					$db->setQuery($query);
					$db->execute();

		self::sendConsumedEmails($subscription_id);
	}

	/**
	 * Set a susbcription as active
	 *
	 * @param int   $subscription_id
	 *
	 * @return bool
	 */
	public static function setReActiveMembershipPlans($subscription_id = 0){
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);

		$now = JFactory::getDate('now')->toSql();

		$query->clear()
						->update('#__osmembership_subscribers')
						->set('plan_subscription_status = 1')
						->set('published = 1')
						->set('updated_date = \''.$now.'\'')
						->set('act = \'re-active last quote by order canceled before\'')
						->where('id = ' . $subscription_id);
					$db->setQuery($query);
					return $db->execute();

		//self::sendReActiveEmails($subscription_id);
	}



	/**
	 * Get information about subscription plans of a user
	 *
	 * @param int $profileId
	 *
	 * @return array
	 */
	public static function getSubscriptions($profileId)
	{
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->select('*')
			->from('#__osmembership_subscribers')
			->where('profile_id = ' . (int) $profileId)
			->order('to_date');
		$db->setQuery($query);
		$rows             = $db->loadObjectList();
		$rowSubscriptions = array();

		foreach ($rows as $row)
		{
			$rowSubscriptions[$row->plan_id][] = $row;
		}

		$planIds = array_keys($rowSubscriptions);

		if (count($planIds) == 0)
		{
			$planIds = array(0);
		}

		$query->clear()
			->select('*')
			->from('#__osmembership_plans')
			->where('id IN (' . implode(',', $planIds) . ')');
		$db->setQuery($query);
		$rowPlans = $db->loadObjectList();

		foreach ($rowPlans as $rowPlan)
		{
			$isActive           = false;
			$isPending          = false;
			$isExpired          = false;
			$subscriptions      = $rowSubscriptions[$rowPlan->id];
			$lastActiveDate     = null;
			$subscriptionId     = null;
			$recurringCancelled = 0;

			foreach ($subscriptions as $subscription)
			{
				if ($subscription->published == 1)
				{
					$isActive       = true;
					$lastActiveDate = $subscription->to_date;
				}
				elseif ($subscription->published == 0)
				{
					$isPending = true;
				}
				elseif ($subscription->published == 2)
				{
					$isExpired = true;
				}

				if ($subscription->recurring_subscription_cancelled)
				{
					$recurringCancelled = 1;
				}

				if ($subscription->subscription_id && !$subscription->recurring_subscription_cancelled && in_array($subscription->payment_method, array('os_authnet', 'os_stripe', 'os_paypal_pro')))
				{
					$subscriptionId = $subscription->subscription_id;
				}

			}
			$rowPlan->subscriptions          = $subscriptions;
			$rowPlan->subscription_id        = $subscriptionId;
			$rowPlan->subscription_from_date = $subscriptions[0]->from_date;
			$rowPlan->subscription_to_date   = $subscriptions[count($subscriptions) - 1]->to_date;
			$rowPlan->recurring_cancelled    = $recurringCancelled;
			if ($isActive)
			{
				$rowPlan->subscription_status  = 1;
				$rowPlan->subscription_to_date = $lastActiveDate;
			}
			elseif ($isPending)
			{
				$rowPlan->subscription_status = 0;
			}
			elseif ($isExpired)
			{
				$rowPlan->subscription_status = 2;
			}
			else
			{
				$rowPlan->subscription_status = 3;
			}
		}

		return $rowPlans;
	}


	/**
	 * Get subscriptions information of the current user
	 *
	 * @return array
	 */
	public static function getUserSubscriptionsInfo()
	{
		static $subscriptions;

		if ($subscriptions === null)
		{
			$user = JFactory::getUser();

			$db    = JFactory::getDbo();
			$query = $db->getQuery(true);

			$now    = JFactory::getDate();
			$nowSql = $db->quote($now->toSql());

			$query->select('plan_id, MIN(from_date) AS active_from_date, MAX(DATEDIFF(' . $nowSql . ', from_date)) AS active_in_number_days')
				->from('#__osmembership_subscribers AS a')
				->where('user_id = ' . (int) $user->id)
				->where('DATEDIFF(' . $nowSql . ', from_date) >= 0')
				->where('published IN (1, 2)')
				->group('plan_id');
			$db->setQuery($query);
			$rows = $db->loadObjectList();

			$subscriptions = array();
			foreach ($rows as $row)
			{
				$subscriptions[$row->plan_id] = $row;
			}
		}

		return $subscriptions;
	}

	public static function setQuotasPlanSubscription($id){
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);

		$query->clear()
				->update('#__osmembership_subscribers')
				->set('plan_subscription_quotas = plan_subscription_quotas+1')
				->where('id = ' . $id);
			$db->setQuery($query);
			return $db->execute();
	}

	public static function unsetQuotasPlanSubscription($id){
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);

		$query->clear()
				->update('#__osmembership_subscribers')
				->set('plan_subscription_quotas = plan_subscription_quotas-1')
				->where('id = ' . $id);
			$db->setQuery($query);
			return $db->execute();
	}

	/**
	 * Get subscription status for a plan of the given user
	 *
	 * @param int $profileId
	 * @param int $planId
	 *
	 * @return int
	 */
	public static function getPlanSubscriptionStatusForUser($profileId, $planId)
	{
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->select('published')
			->from('#__osmembership_subscribers')
			->where('profile_id = ' . $profileId)
			->where('plan_id = ' . $planId)
			->order('to_date');
		$db->setQuery($query);
		$rows = $db->loadObjectList();

		$isActive  = false;
		$isPending = false;
		$isExpired = false;

		foreach ($rows as $subscription)
		{

			if ($subscription->published == 1)
			{
				$isActive = true;
			}
			elseif ($subscription->published == 0)
			{
				$isPending = true;
			}
			elseif ($subscription->published == 2)
			{
				$isExpired = true;
			}
		}

		if ($isActive)
		{
			return 1;
		}
		elseif ($isPending)
		{
			return 0;
		}
		elseif ($isExpired)
		{
			return 2;
		}

		return 3;
	}


	/**
	 * Get plan which the given user has subscribed for
	 *
	 * @param int $userId
	 *
	 * @return array
	 */
	public static function getSubscribedPlans($userId = 0)
	{
		if ($userId == 0)
		{
			$userId = (int) JFactory::getUser()->get('id');
		}

		if ($userId > 0)
		{
			$db    = JFactory::getDbo();
			$query = $db->getQuery(true);

			$query->select('DISTINCT plan_id')
				->from('#__osmembership_subscribers')
				->where('user_id = ' . $userId)
				->where('published IN (1, 2)');
			$db->setQuery($query);

			return $db->loadColumn();
		}

		return array();
	}

	/**
	 * Get subscription from ID
	 *
	 * @param string $subscriptionId
	 *
	 * @return OSMembershipTableSubscriber
	 */
	public static function getSubscription($subscriptionId)
	{
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->select('a.id as sid,a.published as subscription_published , a.*, b.*')
			->from('#__osmembership_subscribers AS a')
			->innerJoin('#__osmembership_plans AS b ON a.plan_id = b.id')
			->where('a.id = ' . $db->quote($subscriptionId))
			->order('a.id DESC');
		$db->setQuery($query);

		return $db->loadObject();
	}

	/**
	 * Get Ids of the plans which current users is not allowed to subscribe because exclusive rule
	 *
	 * @return array
	 */
	public static function getExclusivePlanIds()
	{
		$activePlanIds = self::getActiveMembershipPlans();

		if (count($activePlanIds) > 1)
		{
			$db    = JFactory::getDbo();
			$query = $db->getQuery(true);
			$query->select('a.id')
				->from('#__osmembership_categories AS a')
				->innerJoin('#__osmembership_plans AS b ON a.id = b.category_id')
				->where('a.published = 1')
				->where('a.exclusive_plans = 1')
				->where('b.id IN (' . implode(',', $activePlanIds) . ')');
			$db->setQuery($query);
			$categoryIds = $db->loadColumn();

			if (count($categoryIds))
			{
				$query->clear()
					->select('id')
					->from('#__osmembership_plans')
					->where('category_id IN (' . implode(',', $categoryIds) . ')')
					->where('published = 1');
				$db->setQuery($query);

				return $db->loadColumn();
			}

		}

		return array();
	}



	/**
	 * Method for sending consumed plan emails
	 *
	 * @param array  $rows
	 * @param string $bccEmail
	 * @param int    $time
	 */
	public static function sendConsumedEmails($subscription_id, $bccEmail, $time = 1)
	{

		$config = self::getConfig();
		$db     = JFactory::getDbo();
		$query  = $db->getQuery(true);
		$mailer = static::getMailer($config);

		$logEmails = false;

		if (JMailHelper::isEmailAddress($bccEmail))
		{
			$mailer->addBcc($bccEmail);
		}


		$query->select('*')
				->from('#__osmembership_plans AS a')
				->innerJoin('#__osmembership_subscribers AS b ON a.id = b.plan_id')
				->where('b.id ='.$subscription_id);

		$db->setQuery($query);

		$row = $db->loadObject();

		$fieldSuffixes = array();

		$message  = self::getMessages();
		$timeSent = $db->quote(JFactory::getDate()->toSql());

		$fieldSuffix = '';

		if ($row->language)
		{
			if (!isset($fieldSuffixes[$row->language]))
			{
				$fieldSuffixes[$row->language] = self::getFieldSuffix($row->language);
			}

			$fieldSuffix = $fieldSuffixes[$row->language];
		}

		$planTitle = $row->{'title' . $fieldSuffix};

		$replaces                  = array();
		$replaces['plan_title']    = $planTitle;
		$replaces['first_name']    = $row->first_name;
		$replaces['last_name']     = $row->last_name;
		$replaces['number_days']   = $row->number_days;
		$replaces['membership_id'] = self::formatMembershipId($row, $config);
		$replaces['expire_date']   = JHtml::_('date', $row->to_date, $config->date_format);

		if (strlen($plan->{'consumed_email_subject'}) > 0)
		{
			$subject = $plan->{'consumed_email_subject'};
		}
		elseif (strlen($message->{'consumed_email_subject' . $fieldSuffix}))
		{
			$subject = $message->{'consumed_email_subject' . $fieldSuffix};
		}
		else
		{
			$subject = $message->{'consumed_email_subject'};
		}

		if (self::isValidEmailBody($plan->{'consumed_email_body'}))
		{
			$body = $plan->{'consumed_email_body'};
		}
		elseif (self::isValidEmailBody($message->{'consumed_email_body' . $fieldSuffix}))
		{
			$body = $message->{'consumed_email_body' . $fieldSuffix};
		}
		else
		{
			$body = $message->{'consumed_email_body'};
		}

		foreach ($replaces as $key => $value)
		{
			$key     = strtoupper($key);
			$body    = str_ireplace("[$key]", $value, $body);
			$subject = str_ireplace("[$key]", $value, $subject);
		}

		if (JMailHelper::isEmailAddress($row->email))
		{
			static::send($mailer, array($row->email), $subject, $body, $logEmails, 2, $emailType);

			$mailer->clearAddresses();
		}

		/*$query->clear()
			->update('#__osmembership_subscribers')
			->set($fieldPrefix . 'sent = 1')
			->set($fieldPrefix . 'sent_at = ' . $timeSent)
			->where('id = ' . $row->id);
		$db->setQuery($query);
		$db->execute();*/
	}

	/**
	 * Method for sending re active plan
	 *
	 * @param array  $rows
	 * @param string $bccEmail
	 * @param int    $time
	 */
	public static function sendReActiveEmails($subscription_id, $bccEmail, $time = 1, $order_details)
	{

		$config = self::getConfig();
		$db     = JFactory::getDbo();
		$query  = $db->getQuery(true);
		$mailer = static::getMailer($config);

		$logEmails = false;

		if (JMailHelper::isEmailAddress($bccEmail))
		{
			$mailer->addBcc($bccEmail);
		}


		$query->select('*')
				->from('#__osmembership_plans AS a')
				->innerJoin('#__osmembership_subscribers AS b ON a.id = b.plan_id')
				->where('b.id ='.$subscription_id);

		$db->setQuery($query);

		$row = $db->loadObject();

		$fieldSuffixes = array();

		$message  = self::getMessages();
		$timeSent = $db->quote(JFactory::getDate()->toSql());

		$fieldSuffix = '';

		if ($row->language)
		{
			if (!isset($fieldSuffixes[$row->language]))
			{
				$fieldSuffixes[$row->language] = self::getFieldSuffix($row->language);
			}

			$fieldSuffix = $fieldSuffixes[$row->language];
		}

		$planTitle = $row->{'title' . $fieldSuffix};

		$replaces                  = array();
		$replaces['plan_title']    = $planTitle;
		$replaces['first_name']    = $row->first_name;
		$replaces['last_name']     = $row->last_name;
		$replaces['number_days']   = $row->number_days;
		$replaces['membership_id'] = self::formatMembershipId($row, $config);
		$replaces['update_date']   = JHtml::_('date', $row->to_date, $config->date_format);
		if(is_array(order_details)){
			$replaces['booking_date'] = $order_details["booking_date"];
			$replaces['service_name'] = $order_details["service_name"];
			$replaces['start_time'] = $order_details["start_time"];
			$replaces['end_time'] = $order_details["end_time"];
		}
		

		if (strlen($plan->{'reactive_email_subject'}) > 0)
		{
			$subject = $plan->{'reactive_email_subject'};
		}
		elseif (strlen($message->{'reactive_email_subject' . $fieldSuffix}))
		{
			$subject = $message->{'reactive_email_subject' . $fieldSuffix};
		}
		else
		{
			$subject = $message->{'reactive_email_subject'};
		}

		if (self::isValidEmailBody($plan->{'reactive_email_body'}))
		{
			$body = $plan->{'reactive_email_body'};
		}
		elseif (self::isValidEmailBody($message->{'reactive_email_body' . $fieldSuffix}))
		{
			$body = $message->{'reactive_email_body' . $fieldSuffix};
		}
		else
		{
			$body = $message->{'reactive_email_body'};
		}

		foreach ($replaces as $key => $value)
		{
			$key     = strtoupper($key);
			$body    = str_ireplace("[$key]", $value, $body);
			$subject = str_ireplace("[$key]", $value, $subject);
		}

		if (JMailHelper::isEmailAddress($row->email))
		{
			static::send($mailer, array($row->email), $subject, $body, $logEmails, 2, $emailType);

			$mailer->clearAddresses();
		}

		/*$query->clear()
			->update('#__osmembership_subscribers')
			->set($fieldPrefix . 'sent = 1')
			->set($fieldPrefix . 'sent_at = ' . $timeSent)
			->where('id = ' . $row->id);
		$db->setQuery($query);
		$db->execute();*/
	}


	/**
	 * Method for sending las quote subscription email
	 *
	 * @param array  $rows
	 * @param string $bccEmail
	 * @param int    $time
	 */
	public static function sendLastQuoteEmails($subscription_id, $remainder_quotas = 0, $bccEmail, $time = 1)
	{

		$config = self::getConfig();
		$db     = JFactory::getDbo();
		$query  = $db->getQuery(true);
		$mailer = static::getMailer($config);

		$logEmails = false;

		if (JMailHelper::isEmailAddress($bccEmail))
		{
			$mailer->addBcc($bccEmail);
		}


		$query->select('*')
				->from('#__osmembership_plans AS a')
				->innerJoin('#__osmembership_subscribers AS b ON a.id = b.plan_id')
				->where('b.id ='.$subscription_id);

		$db->setQuery($query);

		$row = $db->loadObject();

		$fieldSuffixes = array();

		$message  = self::getMessages();
		$timeSent = $db->quote(JFactory::getDate()->toSql());

		$fieldSuffix = '';

		if ($row->language)
		{
			if (!isset($fieldSuffixes[$row->language]))
			{
				$fieldSuffixes[$row->language] = self::getFieldSuffix($row->language);
			}

			$fieldSuffix = $fieldSuffixes[$row->language];
		}

		$planTitle = $row->{'title' . $fieldSuffix};

		$replaces                  = array();
		$replaces['plan_title']    = $planTitle;
		$replaces['first_name']    = $row->first_name;
		$replaces['last_name']     = $row->last_name;
		$replaces['number_days']   = $row->number_days;

		if($remainder_quotas)
			$replaces['remainder_quotas'] = $remainder_quotas;

		$replaces['membership_id'] = self::formatMembershipId($row, $config);
		$replaces['expire_date']   = JHtml::_('date', $row->to_date, $config->date_format);

		if (strlen($plan->{'last_quote_email_subject'}) > 0)
		{
			$subject = $plan->{'last_quote_email_subject'};
		}
		elseif (strlen($message->{'last_quote_email_subject' . $fieldSuffix}))
		{
			$subject = $message->{'last_quote_email_subject' . $fieldSuffix};
		}
		else
		{
			$subject = $message->{'last_quote_email_subject'};
		}

		if (self::isValidEmailBody($plan->{'last_quote_email_body'}))
		{
			$body = $plan->{'last_quote_email_body'};
		}
		elseif (self::isValidEmailBody($message->{'consumed_email_body' . $fieldSuffix}))
		{
			$body = $message->{'last_quote_email_body' . $fieldSuffix};
		}
		else
		{
			$body = $message->{'last_quote_email_body'};
		}

		foreach ($replaces as $key => $value)
		{
			$key     = strtoupper($key);
			$body    = str_ireplace("[$key]", $value, $body);
			$subject = str_ireplace("[$key]", $value, $subject);
		}

		if (JMailHelper::isEmailAddress($row->email))
		{
			static::send($mailer, array($row->email), $subject, $body, $logEmails, 2, $emailType);

			$mailer->clearAddresses();
		}

	}


	/**
	 * Check if the given message is a valid email message
	 *
	 * @param $body
	 *
	 * @return bool
	 */
	public static function isValidEmailBody($body)
	{
		if (strlen(trim(strip_tags($body))) > 20)
		{
			return true;
		}

		return false;
	}

	/**
	 * Process sending after all the data has been initialized
	 *
	 * @param JMail  $mailer
	 * @param array  $emails
	 * @param string $subject
	 * @param string $body
	 * @param bool   $logEmails
	 * @param int    $sentTo
	 * @param string $emailType
	 */
	public static function send($mailer, $emails, $subject, $body, $logEmails = false, $sentTo = 0, $emailType = '')
	{
		if (empty($subject))
		{
			return;
		}

		$emails = array_map('trim', $emails);

		for ($i = 0, $n = count($emails); $i < $n; $i++)
		{
			if (!JMailHelper::isEmailAddress($emails[$i]))
			{
				unset($emails[$i]);
			}
		}

		if (count($emails) == 0)
		{
			return;
		}

		$email     = $emails[0];
		$bccEmails = array();

		$mailer->addRecipient($email);

		if (count($emails) > 1)
		{
			unset($emails[0]);
			$bccEmails = $emails;
			$mailer->addBcc($bccEmails);
		}

		$body = self::convertImgTags($body);

		$mailer->setSubject($subject)
			->setBody($body)
			->Send();

		if ($logEmails)
		{
			require_once JPATH_ADMINISTRATOR . '/components/com_osmembership/table/email.php';

			$row             = JTable::getInstance('Email', 'OSMembershipTable');
			$row->sent_at    = JFactory::getDate()->toSql();
			$row->email      = $email;
			$row->subject    = $subject;
			$row->body       = $body;
			$row->sent_to    = $sentTo;
			$row->email_type = $emailType;
			$row->store();

			if (count($bccEmails))
			{
				foreach ($bccEmails as $email)
				{
					$row->id    = 0;
					$row->email = $email;
					$row->store();
				}
			}
		}

	}

	/**
	 * Get the email messages used for sending emails
	 *
	 * @return stdClass
	 */
	public static function getMessages()
	{
		static $message;
		if (!$message)
		{
			$message = new stdClass();
			$db      = JFactory::getDbo();
			$query   = $db->getQuery(true);
			$query->select('*')->from('#__osmembership_messages');
			$db->setQuery($query);
			$rows = $db->loadObjectList();

			for ($i = 0, $n = count($rows); $i < $n; $i++)
			{
				$row           = $rows[$i];
				$key           = $row->message_key;
				$value         = stripslashes($row->message);
				$message->$key = $value;
			}
		}

		return $message;
	}

	/**
	 * Get field suffix used in sql query
	 *
	 * @return string
	 */
	public static function getFieldSuffix($activeLanguage = null)
	{
		$prefix = '';

		if (JLanguageMultilang::isEnabled())
		{
			if (!$activeLanguage)
			{
				$activeLanguage = JFactory::getLanguage()->getTag();
			}

			if ($activeLanguage != self::getDefaultLanguage())
			{
				$db    = JFactory::getDbo();
				$query = $db->getQuery(true);
				$query->select('`sef`')
					->from('#__languages')
					->where('lang_code = ' . $db->quote($activeLanguage))
					->where('published = 1');
				$db->setQuery($query);
				$sef = $db->loadResult();

				if ($sef)
				{
					$prefix = '_' . $sef;
				}
			}
		}

		return $prefix;
	}

	/**
	 * Get front-end default language
	 * @return string
	 */
	public static function getDefaultLanguage()
	{
		$params = JComponentHelper::getParams('com_languages');
		return $params->get('site', 'en-GB');
	}
	

	/**
	 * Format Membership Id
	 *
	 * @param $row
	 * @param $config
	 *
	 * @return string
	 */
	public static function formatMembershipId($row, $config)
	{
		if (!$row->is_profile)
		{
			$db    = JFactory::getDbo();
			$query = $db->getQuery(true);
			$query->select('YEAR(created_date)')
				->from('#__osmembership_subscribers')
				->where('id = ' . (int) $row->profile_id);
			$db->setQuery($query);
			$year = (int) $db->loadResult();
		}
		else
		{
			$year = JHtml::_('date', $row->created_date, 'Y');
		}

		$idPrefix = str_replace('[YEAR]', $year, $config->membership_id_prefix);

		return $idPrefix . $row->membership_id;
	}


	/**
	 * Convert all img tags to use absolute URL
	 *
	 * @param string $html_content
	 *
	 * @return string
	 */
	public static function convertImgTags($html_content)
	{
		$patterns     = array();
		$replacements = array();
		$i            = 0;
		$src_exp      = "/src=\"(.*?)\"/";
		$link_exp     = "[^http:\/\/www\.|^www\.|^https:\/\/|^http:\/\/]";
		$siteURL      = JUri::root();
		preg_match_all($src_exp, $html_content, $out, PREG_SET_ORDER);

		foreach ($out as $val)
		{
			$links = preg_match($link_exp, $val[1], $match, PREG_OFFSET_CAPTURE);
			if ($links == '0')
			{
				$patterns[$i]     = $val[1];
				$patterns[$i]     = "\"$val[1]";
				$replacements[$i] = $siteURL . $val[1];
				$replacements[$i] = "\"$replacements[$i]";
			}
			$i++;
		}

		$mod_html_content = str_replace($patterns, $replacements, $html_content);

		return $mod_html_content;
	}

	/**
	 * Get configuration data and store in config object
	 *
	 * @return object
	 */
	public static function getConfig()
	{
		static $config;

		if (!$config)
		{
			$db     = JFactory::getDbo();
			$query  = $db->getQuery(true);
			$config = new stdClass();
			$query->select('*')
				->from('#__osmembership_configs');
			$db->setQuery($query);
			$rows = $db->loadObjectList();

			for ($i = 0, $n = count($rows); $i < $n; $i++)
			{
				$row          = $rows[$i];
				$key          = $row->config_key;
				$value        = stripslashes($row->config_value);
				$config->$key = $value;
			}
		}

		return $config;
	}

	/**
	 * Create and initialize mailer object from configuration data
	 *
	 * @param $config
	 *
	 * @return JMail
	 */
	public static function getMailer($config)
	{
		$mailer = JFactory::getMailer();

		if ($config->from_name)
		{
			$fromName = $config->from_name;
		}
		else
		{
			$fromName = JFactory::getConfig()->get('fromname');
		}

		if ($config->from_email)
		{
			$fromEmail = $config->from_email;
		}
		else
		{
			$fromEmail = JFactory::getConfig()->get('mailfrom');
		}

		$mailer->setSender(array($fromEmail, $fromName));
		$mailer->isHtml(true);

		if (empty($config->notification_emails))
		{
			$config->notification_emails = $fromEmail;
		}

		return $mailer;
	}
}
