<?php

namespace Drupal\mantle2\Custom;

enum Privacy: string
{
	case PRIVATE = 'PRIVATE';
	case CIRCLE = 'CIRCLE';
	case MUTUAL = 'MUTUAL';
	case PUBLIC = 'PUBLIC';
}
