<?php

namespace Drupal\mantle2\Commands;

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
	 * @param string $identifier The user identifier (ID or username with '@')
	 * @command mantle2:send-email-verification
	 * @aliases m2:send-email-verification m2:verify-email
	 * @usage drush m2:send-email-verification @username
	 */
	public function sendEmailVerification(string $identifier)
	{
		$user = UsersHelper::findBy($identifier);
		if (!$user) {
			$this->stderr()->writeln(
				"User '$identifier' not found. Hint: for usernames, try prefixing with '@'.",
			);
			return;
		}

		if (UsersHelper::isEmailVerified($user)) {
			$this->stderr()->writeln("User '$identifier' email is already verified.");
			return;
		}

		$this->output()->writeln("Sending email verification to user '$identifier'...");
		$success = UsersHelper::sendEmailVerification($user);

		if (!$success) {
			$this->stderr()->writeln("Failed to send email verification to user '$identifier'.");
			return;
		}

		$this->output()->writeln("Email verification sent to user '$identifier'.");
	}

	/**
	 * Send an email campaign to a user.
	 *
	 * @param string $id The email campaign ID.
	 * @param string $identifier The user identifier (ID or username with '@')
	 * @command mantle2:send-email-campaign
	 * @aliases m2:send-email-campaign m2:campaign
	 * @usage drush m2:send-email-campaign welcome_back @username
	 */
	public function sendEmailCampaign(string $id, string $identifier)
	{
		$user = UsersHelper::findBy($identifier);
		if (!$user) {
			$this->stderr()->writeln(
				"User '$identifier' not found. Hint: for usernames, try prefixing with '@'.",
			);
			return;
		}

		$this->output()->writeln("Sending email campaign '$id' to user '$identifier'...");
		$success = UsersHelper::sendEmailCampaign($id, $user);

		if (!$success) {
			$this->stderr()->writeln("Failed to send email campaign '$id' to user '$identifier'.");
			return;
		}

		$this->output()->writeln("Email campaign '$id' sent to user '$identifier'.");
	}

	/**
	 * Add a notification to a user.
	 *
	 * @command mantle2:add-notification
	 * @param string $identifier The user identifier (ID, username with '@', or email).
	 * @option title The title of the notification. Default: 'Test notification'.
	 * @option type The type of the notification (info, warning, error). Default: 'info'.
	 * @option message The message of the notification. Default: 'Test notification'.
	 * @option link An optional link for the notification. Default: null.
	 * @option source The source of the notification. Default: 'drush'.
	 * @aliases m2:add-notification m2:notify
	 * @usage drush m2:add-notification @username --title="Test" --message="This is a test notification."
	 */
	public function addNotification(
		string $identifier,
		array $options = [
			'title' => 'Test notification',
			'type' => 'info',
			'message' => 'Test notification',
			'link' => null,
			'source' => 'drush',
		],
	) {
		$user = UsersHelper::findBy($identifier);
		if (!$user) {
			$this->stderr()->writeln(
				"User '$identifier' not found. Hint: for usernames, try prefixing with '@'.",
			);
			return;
		}

		$this->output()->writeln("Adding notification to user '$identifier'...");
		UsersHelper::addNotification(
			$user,
			$options['title'],
			$options['message'],
			$options['link'],
			$options['type'],
			$options['source'],
		);
		$this->output()->writeln("Notification added to user '$identifier'.");
	}
}
