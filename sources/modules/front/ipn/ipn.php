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

namespace IPS\btcpay\modules\front\ipn;

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!\defined('\IPS\SUITE_UNIQUE_KEY'))
{
	header((isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0') . ' 403 Forbidden');
	exit;
}

/**
 * ipn
 */
class _ipn extends \IPS\Dispatcher\Controller
{
	/**
	 * Execute
	 *
	 * @return	void
	 */
	public function execute()
	{
		parent::execute();
	}

	/**
	 * ...
	 *
	 * @return	void
	 */
	protected function manage()
	{
	}
	
	/**
	 * Update Transaction Status
	 *
	 * @return	void
	 */
	public function transaction()
	{
		try
		{
			$transaction = \IPS\nexus\Transaction::load(\IPS\Request::i()->t);
		}
		catch (\OutOfRangeException $e)
		{
			\IPS\Output::i()->sendOutput('', 500);
		}
		
		$settings = json_decode($transaction->method->settings, TRUE);	
		
		try
		{
			if ($settings['allowed_ip'] !== '')
			{
				$ipAddress = \IPS\Request::i()->ipAddress();
		
				if (\IPS\Request::i()->ipAddress() != $settings['allowed_ip'])
				{
					throw new \Exception("Blocked access for IP {$ipAddress}", 500);
				}
			}
		
			$request = file_get_contents("php://input");
		
			if ($request === FALSE || empty($request)) 
			{
				throw new \Exception('Error reading POST data', 500);
			}
		
			$params = json_decode($request);
			
			$url = \IPS\Http\Url::external($settings['api_url'] . "invoices/{$params->id}")
				->request(10)
				->setHeaders(array('Content-Type' => 'application/json', 'Authorization' => 'Basic ' . $settings['access_token']))
				->get();
		
			$data = json_decode($url);
		
			if ($url->httpResponseCode != 200 || isset($data->error) || !isset($data->data))
			{
				throw new \IPS\Http\Request\Exception($url, 500);
			}

			if ($data->data->status === 'paid')
			{
				if ($transaction->status != \IPS\nexus\Transaction::STATUS_WAITING)
				{
					$transaction->status = \IPS\nexus\Transaction::STATUS_WAITING;
					$transaction->save();
					$transaction->sendNotification();
				}
			}
			else if ($data->data->status === 'confirmed' OR $data->data->status === 'complete')
			{
				if ($transaction->status != \IPS\nexus\Transaction::STATUS_PAID)
				{
					$transaction->gw_id = $data->data->orderId;
					
					$transaction->status = \IPS\nexus\Transaction::STATUS_PAID;
					$transaction->save();
					$transaction->sendNotification();
			
					$maxMind = NULL;
			
					if (\IPS\Settings::i()->maxmind_key)
					{
						$maxMind = new \IPS\nexus\Fraud\MaxMind\Request;
						$maxMind->setTransaction($transaction);
					}
			
					$transaction->checkFraudRulesAndCapture($maxMind);
				}
			}
			else if ($data->data->status === 'invalid' OR $data->data->status === 'expired')
			{
				if ($transaction->status != \IPS\nexus\Transaction::STATUS_REFUSED)
				{
					$transaction->status = \IPS\nexus\Transaction::STATUS_REFUSED;
					$transaction->save();
					$transaction->sendNotification();
				}
			}
			
			\IPS\Output::i()->sendOutput('', 200);
		}
		catch (\Exception $e)
		{
			if ($settings['ipn_logging'])
			{
				\IPS\Log::log($e->getMessage(), 'btcpay_ipn_exception');
			}
		
			\IPS\Output::i()->sendOutput('', 500);	
		}
	}
}