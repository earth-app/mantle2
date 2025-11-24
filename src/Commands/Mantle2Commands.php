<?php

use Drupal\mantle2\Service\UsersHelper;
use Drush\Commands\DrushCommands;

class Mantle2Commands extends DrushCommands
{
	/**
	 * A simple "hello world" command.
	 *
	 * @command mantle2:hi
	 * @aliases m2:hi
	 * @usage drush m2:hi
	 */
	public function hi()
	{
		$this->output()->writeln('Hello, World!');
	}

	/**
	 * Send an email verification to a user.
	 *
	 * @command mantle2:send-email-verification
	 * @aliases m2:send-email-verification m2:verify-email
	 * @usage drush m2:send-email-verification @username
	 */
	public function sendEmailVerification(string $user)
	{
		$user = UsersHelper::findBy($user);
		if (!$user) {
			$this->stderr()->writeln(
				"User '$user' not found. Hint: for usernames, try prefixing with '@'.",
			);
			return;
		}

		if (UsersHelper::isEmailVerified($user)) {
			$this->stderr()->writeln("User '$user' email is already verified.");
			return;
		}

		$this->output()->writeln("Sending email verification to user '$user'...");
		$success = UsersHelper::sendEmailVerification($user);

		if (!$success) {
			$this->stderr()->writeln("Failed to send email verification to user '$user'.");
			return;
		}

		$this->output()->writeln("Email verification sent to user '$user'.");
	}

	/**
	 * Send an email campaign to a user.
	 *
	 * @command mantle2:send-email-campaign
	 * @aliases m2:send-email-campaign m2:campaign
	 * @usage drush m2:send-email-campaign welcome_back @username
	 */
	public function sendEmailCampaign(string $id, string $user)
	{
		$user = UsersHelper::findBy($user);
		if (!$user) {
			$this->stderr()->writeln(
				"User '$user' not found. Hint: for usernames, try prefixing with '@'.",
			);
			return;
		}

		$this->output()->writeln("Sending email campaign '$id' to user '$user'...");
		$success = UsersHelper::sendEmailCampaign($id, $user);

		if (!$success) {
			$this->stderr()->writeln("Failed to send email campaign '$id' to user '$user'.");
			return;
		}

		$this->output()->writeln("Email campaign '$id' sent to user '$user'.");
	}

	/**
	 * Add a notification to a user.
	 *
	 * @command mantle2:add-notification
	 * @aliases m2:add-notification m2:notify
	 * @usage drush m2:add-notification @username --title="Test" --message="This is a test notification."
	 */
	public function addNotification(
		string $user,
		array $options = [
			'title' => 'Test notification',
			'type' => 'info',
			'message' => 'Test notification',
			'link' => null,
			'source' => 'drush',
		],
	) {
		$user = UsersHelper::findBy($user);
		if (!$user) {
			$this->stderr()->writeln(
				"User '$user' not found. Hint: for usernames, try prefixing with '@'.",
			);
			return;
		}

		$this->output()->writeln("Adding notification to user '$user'...");
		UsersHelper::addNotification(
			$user,
			$options['title'],
			$options['message'],
			$options['link'],
			$options['type'],
			$options['source'],
		);
		$this->output()->writeln("Notification added to user '$user'.");
	}
}
