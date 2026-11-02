<?php
/**
 * The webhook channel with a fixed message id and time, for tests.
 *
 * @package wp-updates-notifier
 */

use WPUN\Modules\Webhook\Webhook_Channel;

/**
 * Overrides the two seams of the module that make a message different every time.
 */
class Test_Webhook_Fixed_Channel extends Webhook_Channel {

	const ID   = 'msg_p5jXN8AQM9LWM0D4loKWxJek';
	const TIME = 1614265330;

	/**
	 * A fixed message id.
	 *
	 * @return string
	 */
	protected function message_id(): string {
		return self::ID;
	}

	/**
	 * A fixed time: 2021-02-25 15:02:10 UTC.
	 *
	 * @return int
	 */
	protected function now(): int {
		return self::TIME;
	}
}
