<?php

namespace LiquidMonitorConnector;

interface JobFactory
{
	public function create(int $id): Job;
}
