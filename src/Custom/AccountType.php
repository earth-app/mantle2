<?php

namespace Drupal\mantle2\Custom;

enum AccountType: string
{
	case FREE = 'free';
	case PRO = 'pro';
	case WRITER = 'writer';
	case ORGANIZER = 'organizer';
	case ADMINISTRATOR = 'administrator';
}
