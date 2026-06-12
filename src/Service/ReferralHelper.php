<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Drupal\user\UserInterface;
use Exception;

class ReferralHelper
{
	public static function getCode(UserInterface $user): string
	{
		$data = CloudHelper::sendRequest(
			'/v1/users/referral/' . GeneralHelper::formatId($user->id()),
		);
		return $data['code'] ?? '';
	}

	// { code, clicks, conversions, converted_ids }
	public static function getStats(UserInterface $user): array
	{
		$data = CloudHelper::sendRequest(
			'/v1/users/referral/' . GeneralHelper::formatId($user->id()) . '/stats',
		);
		return $data;
	}

	public static function recordClick(string $code): void
	{
		try {
			CloudHelper::sendRequest('/v1/users/referral/click', 'POST', ['code' => $code]);
		} catch (Exception $e) {
			Drupal::logger('mantle2')->warning('Failed to record referral click: %message', [
				'%message' => $e->getMessage(),
			]);
		}
	}

	// returns the numeric referrer id on success, null otherwise
	public static function attributeReferral(UserInterface $newUser, string $code): ?string
	{
		try {
			$data = CloudHelper::sendRequest('/v1/users/referral/convert', 'POST', [
				'code' => $code,
				'user_id' => GeneralHelper::formatId($newUser->id()),
			]);

			if (($data['ok'] ?? false) === true) {
				return $data['referrer_id'] ?? null;
			}

			return null;
		} catch (Exception $e) {
			// never let a cloud blip throw into signup/verification
			Drupal::logger('mantle2')->warning(
				'Failed to attribute referral for %user_id: %message',
				[
					'%user_id' => GeneralHelper::formatId($newUser->id()),
					'%message' => $e->getMessage(),
				],
			);
			return null;
		}
	}
}
