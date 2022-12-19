<?php
/**
 * @brief		BTCPay Gateway
 * @author		<a href=''>RangeMachine</a>
 * @copyright	(c) 2019 RangeMachine
 * @package		Invision Community
 * @subpackage	BTCPay Gateway
 * @since		23 Jul 2019
 * @version		
 */

namespace IPS\btcpay;

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!\defined('\IPS\SUITE_UNIQUE_KEY'))
{
	header((isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0') . ' 403 Forbidden');
	exit;
}

/**
 * BTCPay Gateway
 */
class _Gateway extends \IPS\nexus\Gateway
{		
	/* !Features */

	const SUPPORTS_REFUNDS = FALSE;
	const SUPPORTS_PARTIAL_REFUNDS = FALSE;

	/* !Payment Gateway */


	/**
	 * Check the gateway can process this...
	 *
	 * @param	$amount			\IPS\nexus\Money		The amount
	 * @param	$billingAddress	\IPS\GeoLocation|NULL	The billing address, which may be NULL if one if not provided
	 * @param	$customer		\IPS\nexus\Customer		The customer (Default NULL value is for backwards compatibility - it should always be provided.)
	 * @param	array			$recurrings				Details about recurring costs
	 * @see		<a href="https://stripe.com/docs/currencies">Supported Currencies</a>
	 * @return	bool
	 */
	public function checkValidity(\IPS\nexus\Money $amount, ?\IPS\GeoLocation $billingAddress = NULL, ?\IPS\nexus\Customer $customer = NULL, $recurrings=array())
	{
      	$settings = json_decode($this->settings, TRUE);

		if (isset($settings['paymethod_groups']))
		{
			if ($settings['paymethod_groups'] !== '*' AND !$customer->inGroup($settings['paymethod_groups']))
			{
				return FALSE;
			}
		}
      
		return parent::checkValidity($amount, $billingAddress, $customer, $recurrings);
	}

	/**
	 * Authorize
	 *
	 * @param	\IPS\nexus\Transaction					$transaction	Transaction
	 * @param	array|\IPS\nexus\Customer\CreditCard	$values			Values from form OR a stored card object if this gateway supports them
	 * @param	\IPS\nexus\Fraud\MaxMind\Request|NULL	$maxMind		*If* MaxMind is enabled, the request object will be passed here so gateway can additional data before request is made	
	 * @param	array									$recurrings		Details about recurring costs
	 * @param	string|NULL								$source			'checkout' if the customer is doing this at a normal checkout, 'renewal' is an automatically generated renewal invoice, 'manual' is admin manually charging. NULL is unknown
	 * @return	\IPS\DateTime|NULL						Auth is valid until or NULL to indicate auth is good forever
	 * @throws	\LogicException							Message will be displayed to user
	 */
	public function auth(\IPS\nexus\Transaction $transaction, $values, \IPS\nexus\Fraud\MaxMind\Request $maxMind = NULL, $recurrings = array(), $source = NULL)
	{
		$transaction->save();
		$summary = $transaction->invoice->summary();

		foreach ($summary['items'] as $item)
		{
			$productNames[] = $item->quantity .' x '. $item->name;
		}

		$params = array(
			'price'				=> $transaction->amount->amount,
			'currency'			=> $transaction->amount->currency,
			'orderId'				=> (string) $transaction->invoice->id,
			'itemDesc'			=> implode(', ', $productNames),
			'fullNotifications'	=> TRUE,
			'redirectURL'			=> (string) \IPS\Http\Url::internal("app=nexus&controller=checkout&do=transaction&id=&t={$transaction->id}", 'front', 'nexus_checkout', \IPS\Settings::i()->nexus_https),		  
			'notificationUrl'		=> (string) \IPS\Http\Url::internal("app=btcpay&controller=ipn&do=transaction&t={$transaction->id}", 'front', 'btcpay_ipn', \IPS\Settings::i()->nexus_https)
		);

		$settings = json_decode($this->settings, TRUE);
				
		try
		{
			$url = \IPS\Http\Url::external($settings['api_url'] . "invoices")
				->request(10)
				->setHeaders(array('Content-Type' => "application/json", 'Authorization' => "Basic " . $settings['access_token']))
				->post(json_encode($params));
		}
		catch (\IPS\Http\Request\CurlException $e)
		{		
			\IPS\Log::log($e->getMessage(), 'btcpay_exception');

			throw new \LogicException($transaction->member->language()->get('btcpay_internal_error'), 200);
		}

		$data = json_decode($url);
		
		if ($url->httpResponseCode != 200 || isset($data->error) || !isset($data->data))
		{
			\IPS\Log::log($url, 'btcpay_exception');

			throw new \LogicException($transaction->member->language()->get('btcpay_internal_error'), 200);
		}

		\IPS\Output::i()->redirect($data->data->url);
	}
		
	/* !ACP Configuration */
	
	/**
	 * [Node] Add/Edit Form
	 *
	 * @param	\IPS\Helpers\Form	$form	The form
	 * @return	void
	 */
	public function form(&$form)
	{
		$form->addHeader('btcpay_basic_settings');

		$form->add(new \IPS\Helpers\Form\Translatable('paymethod_name', NULL, TRUE, array( 'app' => 'nexus', 'key' => "nexus_paymethod_{$this->id}" ) ) );
		$form->add(new \IPS\Helpers\Form\Select('paymethod_countries', ($this->id and $this->countries !== '*') ? explode(',', $this->countries) : '*', FALSE, array('options' => array_map(function($val)
		{
			return "country-{$val}";
		}, array_combine(\IPS\GeoLocation::$countries, \IPS\GeoLocation::$countries)), 'multiple' => TRUE, 'unlimited' => '*', 'unlimitedLang' => 'no_restriction')));
		$this->settings( $form );
		parent::form( $form );
	}

	/**
	 * Settings
	 *
	 * @param	\IPS\Helpers\Form	$form	The form
	 * @return	void
	 */
	public function settings(&$form)
	{		
		$settings = json_decode($this->settings, TRUE);
		
		$form->addHeader('btcpay_ipn_settings');

		$form->add(new \IPS\Helpers\Form\Text('btcpay_api_url', isset($settings['api_url']) ? $settings['api_url'] : '', TRUE));
		$form->add(new \IPS\Helpers\Form\Text('btcpay_access_token', isset($settings['access_token']) ? $settings['access_token'] : '', TRUE));
		$form->add(new \IPS\Helpers\Form\Text('btcpay_allowed_ip', isset($settings['allowed_ip']) ? $settings['allowed_ip'] : '', FALSE));
		$form->add(new \IPS\Helpers\Form\YesNo('btcpay_ipn_logging', isset($settings['ipn_logging']) ? $settings['ipn_logging'] : 0, TRUE));
		
		$form->addHeader('btcpay_user_settings');

		$form->add(new \IPS\Helpers\Form\Translatable(
			'btcpay_instructions',
			NULL,
			TRUE, 
			array(
				'app' => 'nexus', 
				'key' => ($this->id ? "nexus_gateway_{$this->id}_ins" : NULL),
				'editor' => array('app' => 'nexus', 'key' => 'Admin', 'autoSaveKey' => ($this->id ? "nexus-gateway-{$this->id}" : "nexus-new-gateway"), 
					'attachIds' => $this->id ? array($this->id, NULL, 'description') : NULL, 
					'minimize' => 'btcpay_instructions_placeholder')
				)
			)
		);

		$form->addHeader('btcpay_groups_settings');

		$form->add(new \IPS\Helpers\Form\Select(
			'btcpay_paymethod_groups',
			isset($settings['paymethod_groups']) ? $settings['paymethod_groups'] : '*',
			false,
			array(
				'options' 		=> array_combine(array_keys(\IPS\Member\Group::groups( ) ), array_map(function($_group) { return (string) $_group; }, \IPS\Member\Group::groups())),
				'multiple' 		=> true,
				'unlimited' 	=> '*',
				'unlimitedLang' => 'no_restriction'
			)
		));
	}

	/**
	 * [Node] Format form values from add/edit form for save
	 *
	 * @param	array	$values	Values from the form
	 * @return	array
	 */
	public function formatFormValues($values)
	{
		if (isset($values['btcpay_instructions']))
		{
			\IPS\Lang::saveCustom('nexus', "nexus_gateway_{$this->id}_ins", $values['btcpay_instructions']);

			unset($values['btcpay_instructions']);
		}

		if (!$this->id)
		{
			$this->save();

			\IPS\File::claimAttachments('nexus_gateway_new', $this->id, NULL, 'gateway', TRUE);
		}

		return parent::formatFormValues( $values );
	}

	/**
	 * Test Settings
	 *
	 * @param	array	$settings	Settings
	 * @return	array
	 * @throws	\InvalidArgumentException
	 */
	public function testSettings($settings = array())
	{		try
		{
			$url = \IPS\Http\Url::external($settings['api_url'] . "invoices")
				->request(10)
				->setHeaders(array('Content-Type' => "application/json", 'Authorization' => "Basic " . $settings['access_token']))
				->get();
		}
		catch (\IPS\Http\Request\CurlException $e)
		{		
			throw new \InvalidArgumentException(\IPS\Member::loggedIn()->language()->get('btcpay_invalid_api_url'), 200);
		}

		$data = json_decode($url);
		
		if ($url->httpResponseCode != 200 || isset($data->error))
		{
			throw new \InvalidArgumentException(\IPS\Member::loggedIn()->language()->get('btcpay_invalid_access_token'), 200);
		}
		
		return $settings;
	}
}
