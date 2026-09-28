<?php

namespace Modules\AdvancedAvailabilityReports\Actions;

use CController;
use CControllerResponseData;
use Modules\AdvancedAvailabilityReports\Includes\HostDetailService;
use Modules\AdvancedAvailabilityReports\Includes\ReportService;

class HostDetail extends CController {

	public function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$filters = ReportService::normalizeFilters([
			'report_type' => getRequest('report_type', 'technical'),
			'period' => getRequest('period', 'daily'),
			'groupid' => getRequest('groupid', 0),
			'hostid' => getRequest('hostid', 0),
			'availability_mode' => getRequest('availability_mode', 'auto'),
			'maintenance_mode' => getRequest('maintenance_mode', 'exclude'),
			'sla_target' => getRequest('sla_target', 99.9),
			'company_name' => getRequest('company_name', ''),
			'report_title' => getRequest('report_title', 'Monitoreo y disponibilidad de infraestructura'),
			'date_from' => getRequest('date_from', ''),
			'date_to' => getRequest('date_to', '')
		]);

		$data = (new HostDetailService())->build((int) getRequest('hostid', 0), $filters);
		$availability = (new ReportService())->build($filters, false);
		$data['availability'] = $availability['rows'][0] ?? null;
		if ($data['availability'] !== null) {
			$data['problem_summary']['active'] = (int) ($data['availability']['health']['active'] ?? 0);
			$data['problem_summary']['active_max_severity'] =
				(int) ($data['availability']['health']['max_severity'] ?? 0);
			$data['problem_summary']['health'] = (string) ($data['availability']['health']['status'] ?? 'Normal');
		}
		$data['title'] = _('Detalle técnico del host');
		$response = new CControllerResponseData($data);
		$response->setTitle($data['title']);
		$this->setResponse($response);
	}
}
