<?php

namespace LiquidMonitorConnector\HealthCheck;

enum HealthCheckStatusEnum: int
{
	case INFO = -1;
	case OK = 0;
	case WARNING = 1;
	case ERROR = 2;
	case CRITICAL = 3;
}
