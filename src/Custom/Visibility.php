<?php

namespace Drupal\mantle2\Custom;

enum Visibility: string
{
	case PUBLIC = 'PUBLIC';
	case UNLISTED = 'UNLISTED';
	case PRIVATE = 'PRIVATE';
}
