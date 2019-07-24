//<?php

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!\defined( '\IPS\SUITE_UNIQUE_KEY'))
{
	exit;
}

class btcpay_hook_Gateway extends _HOOK_CLASS_
{
	/**
	 * Gateways
	 * @RangeMachine
	 * @return	array
	 */
	static public function gateways()
	{
		try
		{
			try
			{
				try
				{
					$array = parent::gateways();
			        $array['btcpay'] = 'IPS\btcpay\Gateway';
		
			      	return $array;
				}
				catch (\RuntimeException $e)
				{
					if (method_exists(get_parent_class(), __FUNCTION__))
					{
						return \call_user_func_array('parent::' . __FUNCTION__, \func_get_args());
					}
					else
					{
						throw $e;
					}
				}
			}
			catch (\RuntimeException $e)
			{
				if ( method_exists(get_parent_class(), __FUNCTION__))
				{
					return \call_user_func_array('parent::' . __FUNCTION__, \func_get_args());
				}
				else
				{
					throw $e;
				}
			}
		}
		catch ( \RuntimeException $e )
		{
			if ( method_exists( get_parent_class(), __FUNCTION__ ) )
			{
				return \call_user_func_array( 'parent::' . __FUNCTION__, \func_get_args() );
			}
			else
			{
				throw $e;
			}
		}
	}
}
