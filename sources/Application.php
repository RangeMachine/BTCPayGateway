<?php
/**
 * @brief		BTCPay Gateway Application Class
 * @author		<a href=''>RangeMachine</a>
 * @copyright	(c) 2019 RangeMachine
 * @package		Invision Community
 * @subpackage	BTCPay Gateway
 * @since		23 Jul 2019
 * @version		
 */
 
namespace IPS\btcpay;

/**
 * BTCPay Gateway Application Class
 */
class _Application extends \IPS\Application
{
	/**
	 * [Node] Get Icon for tree
	 *
	 * @note	Return the class for the icon (e.g. 'globe')
	 * @return	string|null
	 */
	protected function get__icon()
	{
		return 'btc';
	}
}