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

require_once '../../../init.php';

try
{
	$transaction = \IPS\nexus\Transaction::load(\IPS\Request::i()->t);

	if ($transaction->status != \IPS\nexus\Transaction::STATUS_PENDING && $transaction->status != \IPS\nexus\Transaction::STATUS_WAITING)
	{
		throw new \OutofRangeException;
	}
}
catch (\OutOfRangeException $e)
{
	if ($settings['ipn_logging'])
	{
		\IPS\Log::log('Transaction not found', 'btcpay_ipn_exception');
	}

	\IPS\Output::i()->sendOutput('', 500);
}

try
{
	$settings = json_decode($transaction->method->settings, TRUE);	

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

	if ($params->status !== 'paid' && $params->status !== 'confirmed')
	{
		\IPS\Output::i()->sendOutput('', 200);
	}
	
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

		\IPS\Output::i()->sendOutput('', 200);	
	}
	else if ($data->data->status === 'confirmed')
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

		\IPS\Output::i()->sendOutput('', 200);	
	}
}
catch (\Exception $e)
{
	if ($settings['ipn_logging'])
	{
		\IPS\Log::log($e->getMessage(), 'btcpay_ipn_exception');
	}

	\IPS\Output::i()->sendOutput('', 500);	
}
 
?>
