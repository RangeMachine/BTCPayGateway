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

		$settings = json_decode($this->settings, TRUE);
		$summary = $transaction->invoice->summary();

		foreach ($summary['items'] as $item)
		{
			$productNames[] = $item->quantity .' x '. $item->name;
		}

		$params = array (
		  'price'				=> $transaction->amount->amount,
		  'currency'			=> (string) $transaction->amount->currency,
		  'orderId'				=> (string) $transaction->invoice->id,
		  'itemDesc'			=> implode(', ', $productNames),
		  'fullNotifications'	=> TRUE,
		  'redirectURL'			=> (string) \IPS\Http\Url::internal("app=nexus&controller=checkout&do=transaction&id=&t={$transaction->id}", 'front', 'nexus_checkout', \IPS\Settings::i()->nexus_https),		  
		  'notificationUrl'		=> \IPS\Settings::i()->base_url . "applications/btcpay/interface/btcpay.php?&t={$transaction->id}"
		);
				
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
	 * Settings
	 *
	 * @param	\IPS\Helpers\Form	$form	The form
	 * @return	void
	 */
	public function settings(&$form)
	{		
		$settings = json_decode($this->settings, TRUE);

		$form->add(new \IPS\Helpers\Form\Text('btcpay_api_url', isset($settings['api_url']) ? $settings['api_url'] : '', TRUE));
		$form->add(new \IPS\Helpers\Form\Text('btcpay_access_token', isset($settings['access_token']) ? $settings['access_token'] : '', TRUE));
		$form->add(new \IPS\Helpers\Form\Text('btcpay_allowed_ip', isset($settings['allowed_ip']) ? $settings['allowed_ip'] : '', FALSE));
		$form->add(new \IPS\Helpers\Form\YesNo('btcpay_ipn_logging', isset($settings['ipn_logging']) ? $settings['ipn_logging'] : 0, TRUE));
		$form->add(new \IPS\Helpers\Form\Translatable('btcpay_instructions', NULL, TRUE, array('app' => 'nexus', 'key' => ($this->id ? "nexus_gateway_{$this->id}_ins" : NULL), 'editor' => array('app' => 'nexus', 'key' => 'Admin', 'autoSaveKey' => ($this->id ? "nexus-gateway-{$this->id}" : "nexus-new-gateway" ), 'attachIds' => $this->id ? array($this->id, NULL, 'description') : NULL, 'minimize' => 'btcpay_instructions_placeholder'))));
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
	{
		try
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