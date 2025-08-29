<?php

namespace Drupal\mantle2\Custom;

enum EventType: string
{
	case IN_PERSON = 'IN_PERSON';
	case ONLINE = 'ONLINE';
	case HYBRID = 'HYBRID';
}
