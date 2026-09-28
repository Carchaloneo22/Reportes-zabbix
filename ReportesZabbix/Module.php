<?php

namespace Modules\AdvancedAvailabilityReports;

use APP;
use CMenuItem;
use Zabbix\Core\CModule;

class Module extends CModule {

	public function init(): void {
		APP::Component()->get('menu.main')
			->findOrAdd(_('Reports'))
				->getSubmenu()
					->add(
						(new CMenuItem(_('Monitoreo y disponibilidad')))
							->setAction('availability.reports')
					);
	}
}
