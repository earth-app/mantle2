<?php

namespace Drupal\mantle2\Custom;

enum ActivityType: string
{
	case HOBBY = 'HOBBY';
	case SPORT = 'SPORT';
	case WORK = 'WORK';
	case STUDY = 'STUDY';
	case TRAVEL = 'TRAVEL';
	case SOCIAL = 'SOCIAL';
	case RELAXATION = 'RELAXATION';
	case HEALTH = 'HEALTH';
	case PROJECT = 'PROJECT';
	case PERSONAL_GOAL = 'PERSONAL_GOAL';
	case COMMUNITY_SERVICE = 'COMMUNITY_SERVICE';
	case CREATIVE = 'CREATIVE';
	case FAMILY = 'FAMILY';
	case HOLIDAY = 'HOLIDAY';
	case ENTERTAINMENT = 'ENTERTAINMENT';
	case LEARNING = 'LEARNING';
	case NATURE = 'NATURE';
	case TECHNOLOGY = 'TECHNOLOGY';
	case ART = 'ART';
	case SPIRITUALITY = 'SPIRITUALITY';
	case FINANCE = 'FINANCE';
	case HOME_IMPROVEMENT = 'HOME_IMPROVEMENT';
	case PETS = 'PETS';
	case FASHION = 'FASHION';
	case OTHER = 'OTHER';
}
