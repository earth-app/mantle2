<?php

namespace Drupal\mantle2\Custom;

/**
 * Review state of a user's Verified Publisher application.
 *
 * Stored on field_verified_publisher_state as the ORDINAL index into cases(), matching
 * AccountType and Visibility. NONE must stay first; the field's default_value is 0.
 */
enum VerifiedPublisherState: string
{
	case NONE = 'none';
	case PENDING = 'pending';
	case APPROVED = 'approved';
	case DENIED = 'denied';
	case REVOKED = 'revoked';
}
